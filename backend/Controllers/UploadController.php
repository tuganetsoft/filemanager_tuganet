<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Notification\Adapters\EmailNotification;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\TmpfsInterface;

class UploadController
{
    protected $auth;

    protected $config;

    protected $storage;

    protected $tmpfs;

    protected $notification;

    protected $logger;

    protected $userHomeDir;

    public function __construct(Config $config, AuthInterface $auth, Filesystem $storage, TmpfsInterface $tmpfs, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->auth = $auth;
        $this->tmpfs = $tmpfs;
        $this->logger = $logger;

        // Create notification service directly - bypassing DI
        try {
            $this->notification = new EmailNotification($config, $auth, $logger);
            $this->notification->init();
        } catch (\Exception $e) {
            $this->notification = null;
        }

        $user = $this->auth->user() ?: $this->auth->getGuest();
        $this->userHomeDir = $user->getHomeDir();

        $this->storage = $storage;
        $this->storage->setPathPrefix($this->userHomeDir);
        
        // Flush any pending notifications from previous batch uploads
        if ($this->notification) {
            $this->flushNotifications();
        }
    }

    public function chunkCheck(Request $request, Response $response)
    {
        $file_name = $request->input('resumableFilename', 'file');
        $identifier = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('resumableIdentifier'));
        $chunk_number = (int) $request->input('resumableChunkNumber');

        $chunk_file = 'multipart_'.$identifier.$file_name.'.part'.$chunk_number;

        if ($this->tmpfs->exists($chunk_file)) {
            return $response->json('Chunk exists', 200);
        }

        return $response->json('Chunk does not exists', 204);
    }

    public function upload(Request $request, Response $response)
    {
        $file_name = $request->input('resumableFilename', 'file');
        $destination = $request->input('resumableRelativePath');
        $chunk_number = (int) $request->input('resumableChunkNumber');
        $total_chunks = (int) $request->input('resumableTotalChunks');
        $total_size = (int) $request->input('resumableTotalSize');
        $identifier = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('resumableIdentifier'));

        $filebag = $request->files;
        $file = $filebag->get('file');

        $overwrite_on_upload = (bool) $this->config->get('overwrite_on_upload', false);

        // php 8.1 fix
        // remove new key 'full_path' so it can preserve compatibility with symfony FileBag
        // see https://php.watch/versions/8.1/$_FILES-full-path
        if ($file && is_array($file) && array_key_exists('full_path', $file)) {
            unset($file['full_path']);
            $filebag->set('file', $file);
            $file = $filebag->get('file');
        }

        if (! $file || ! $file->isValid() || $file->getSize() > $this->config->get('frontend_config.upload_max_size')) {
            return $response->json('Bad file', 422);
        }

        $prefix = 'multipart_'.$identifier;

        if ($this->tmpfs->exists($prefix.'_error')) {
            return $response->json('Chunk too big', 422);
        }

        $stream = fopen($file->getPathName(), 'r');

        $this->tmpfs->write($prefix.$file_name.'.part'.$chunk_number, $stream);

        // check if all the parts present, and create the final destination file
        $chunks_size = 0;
        foreach ($this->tmpfs->findAll($prefix.'*') as $chunk) {
            $chunks_size += $chunk['size'];
        }

        // file too big, cleanup to protect server, set error trap
        if ($chunks_size > $this->config->get('frontend_config.upload_max_size')) {
            foreach ($this->tmpfs->findAll($prefix.'*') as $tmp_chunk) {
                $this->tmpfs->remove($tmp_chunk['name']);
            }
            $this->tmpfs->write($prefix.'_error', '');

            return $response->json('Chunk too big', 422);
        }

        // if all the chunks are present, create final file and store it
        if ($chunks_size >= $total_size) {
            for ($i = 1; $i <= $total_chunks; ++$i) {
                $part = $this->tmpfs->readStream($prefix.$file_name.'.part'.$i);
                $this->tmpfs->write($file_name, $part['stream'], true);
            }

            $final = $this->tmpfs->readStream($file_name);
            $res = $this->storage->store($destination, $final['filename'], $final['stream'], $overwrite_on_upload);

            // cleanup
            $this->tmpfs->remove($file_name);
            foreach ($this->tmpfs->findAll($prefix.'*') as $expired_chunk) {
                $this->tmpfs->remove($expired_chunk['name']);
            }

            $timestamp = date('Y-m-d H:i:s');
            $this->logger->log("[{$timestamp}] Upload: File stored successfully: {$final['filename']} to {$destination}");
            
            if ($res && $this->notification) {
                try {
                    $uploadFolder = $this->normalizeUploadPath($this->userHomeDir, $destination);
                    $storedFilename = $final['filename'];
                    $this->queueNotification($uploadFolder, $storedFilename);
                } catch (\Exception $e) {
                    $this->logger->log("[{$timestamp}] Upload: Notification error: " . $e->getMessage());
                }
            }

            return $res ? $response->json('Stored') : $response->json('Error storing file');
        }

        return $response->json('Uploaded');
    }

    protected function queueNotification(string $uploadFolder, string $filename): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $batchWindow = 5; // seconds to wait for more files before sending
        $privateDir = dirname(__DIR__, 2) . '/private';
        $queueFile = $privateDir . '/notification_queue.json';
        
        // Use file locking for safe concurrent access
        $lockFile = $privateDir . '/notification_queue.lock';
        $lock = fopen($lockFile, 'c');
        flock($lock, LOCK_EX);
        
        try {
            // Read existing queue
            $queue = [];
            if (file_exists($queueFile)) {
                $queue = json_decode(file_get_contents($queueFile), true) ?: [];
            }
            
            $folderKey = md5($uploadFolder);
            $now = time();
            
            // Add file to queue for this folder
            if (!isset($queue[$folderKey])) {
                $queue[$folderKey] = [
                    'folder' => $uploadFolder,
                    'files' => [],
                    'first_upload' => $now,
                    'last_upload' => $now
                ];
            }
            
            $queue[$folderKey]['files'][] = $filename;
            $queue[$folderKey]['last_upload'] = $now;
            
            // Process any OTHER folders whose batch window has expired (not current folder)
            $toSend = [];
            foreach ($queue as $key => $entry) {
                if ($key !== $folderKey) {
                    $timeSinceLastUpload = $now - $entry['last_upload'];
                    if ($timeSinceLastUpload >= $batchWindow) {
                        $toSend[$key] = $entry;
                        unset($queue[$key]);
                    }
                }
            }
            
            // Save updated queue
            file_put_contents($queueFile, json_encode($queue), LOCK_EX);
            
            $this->logger->log("[{$timestamp}] Upload: Queued notification for {$filename} in {$uploadFolder} (queue has " . count($queue) . " pending folders)");
            
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        
        // Send notifications for expired batches (outside the lock)
        foreach ($toSend as $entry) {
            try {
                $this->notification->notifyUpload($entry['folder'], $entry['files']);
                $this->logger->log("[{$timestamp}] Batch notification sent for " . count($entry['files']) . " files to " . $entry['folder']);
            } catch (\Exception $e) {
                $this->logger->log("[{$timestamp}] Batch notification error: " . $e->getMessage());
            }
        }
    }
    
    public function flushNotifications(): void
    {
        // This method can be called by any request to flush pending notifications
        $batchWindow = 5;
        $privateDir = dirname(__DIR__, 2) . '/private';
        $queueFile = $privateDir . '/notification_queue.json';
        $lockFile = $privateDir . '/notification_queue.lock';
        
        if (!file_exists($queueFile)) return;
        
        $lock = fopen($lockFile, 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return; // Skip if another process is handling it
        }
        
        try {
            $queue = json_decode(file_get_contents($queueFile), true) ?: [];
            $now = time();
            $toSend = [];
            
            foreach ($queue as $key => $entry) {
                $timeSinceLastUpload = $now - $entry['last_upload'];
                if ($timeSinceLastUpload >= $batchWindow) {
                    $toSend[$key] = $entry;
                    unset($queue[$key]);
                }
            }
            
            file_put_contents($queueFile, json_encode($queue), LOCK_EX);
            
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        
        foreach ($toSend as $entry) {
            try {
                $this->notification->notifyUpload($entry['folder'], $entry['files']);
                $this->logger->log("[".date('Y-m-d H:i:s')."] Flushed batch notification for " . count($entry['files']) . " files to " . $entry['folder']);
            } catch (\Exception $e) {
                $this->logger->log("[".date('Y-m-d H:i:s')."] Flush notification error: " . $e->getMessage());
            }
        }
    }

    protected function normalizeUploadPath(string $homeDir, string $destination): string
    {
        $homeDir = '/' . trim($homeDir, '/');
        $destination = trim($destination, '/');
        
        if (empty($destination) || $destination === '.') {
            return $homeDir === '/' ? '/' : $homeDir;
        }
        
        $fullPath = $homeDir === '/' ? '/' . $destination : $homeDir . '/' . $destination;
        
        $parts = explode('/', $fullPath);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }
        
        return '/' . implode('/', $normalized);
    }
}
