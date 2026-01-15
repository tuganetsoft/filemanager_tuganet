<?php

namespace Filegator\Services\Notification\Adapters;

use Filegator\Config\Config;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Notification\NotificationInterface;
use Filegator\Services\Service;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailNotification implements Service, NotificationInterface
{
    protected $config;
    protected $auth;
    protected $logger;
    protected $smtpConfig = [];

    public function __construct(Config $config, AuthInterface $auth, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->auth = $auth;
        $this->logger = $logger;
    }

    public function init(array $config = [])
    {
        $this->smtpConfig = $this->config->get('smtp', []);
    }

    public function notifyUpload(string $uploadPath, array $files): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->logger->log("[{$timestamp}] NOTIFICATION: notifyUpload called for path: {$uploadPath}, files: " . implode(', ', $files));
        
        if (empty($this->smtpConfig['enabled'])) {
            $this->logger->log("[{$timestamp}] NOTIFICATION: SMTP is disabled, skipping email");
            return;
        }
        
        if (empty($files)) {
            $this->logger->log("[{$timestamp}] NOTIFICATION: No files provided, skipping email");
            return;
        }

        $users = $this->findUsersForPath($uploadPath);
        $this->logger->log("[{$timestamp}] NOTIFICATION: Found " . count($users) . " matching user(s) for path: {$uploadPath}");
        
        if (empty($users)) {
            $this->logger->log("[{$timestamp}] NOTIFICATION: No users matched for this path");
            return;
        }

        foreach ($users as $user) {
            $this->logger->log("[{$timestamp}] NOTIFICATION: Checking user: {$user->getUsername()}, email: {$user->getEmail()}, homedir: {$user->getHomeDir()}");
            
            if (empty($user->getEmail())) {
                $this->logger->log("[{$timestamp}] NOTIFICATION: User {$user->getUsername()} has no email, skipping");
                continue;
            }
            
            $this->sendUploadNotification($user, $uploadPath, $files);
        }
    }

    protected function findUsersForPath(string $uploadPath): array
    {
        $timestamp = date('Y-m-d H:i:s');
        $matchedUsers = [];
        $allUsers = $this->auth->allUsers();
        
        $this->logger->log("[{$timestamp}] MATCHING: Total users from allUsers(): " . count($allUsers));
        
        $targetPath = '/' . trim($uploadPath, '/');
        if ($targetPath !== '/') {
            $targetPath = rtrim($targetPath, '/');
        }
        
        $this->logger->log("[{$timestamp}] MATCHING: Target path normalized: '{$targetPath}'");
        
        foreach ($allUsers as $user) {
            $homedir = $user->getHomeDir();
            $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' raw homedir: '{$homedir}'");
            
            if (empty($homedir)) {
                $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' has empty homedir, skipping");
                continue;
            }
            
            $homedir = '/' . trim($homedir, '/');
            if ($homedir !== '/') {
                $homedir = rtrim($homedir, '/');
            }
            
            $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' normalized homedir: '{$homedir}'");
            
            // Match users with root access
            if ($homedir === '/') {
                $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' has root access, MATCHED");
                $matchedUsers[] = $user;
                continue;
            }
            
            // Match exact path
            if ($homedir === $targetPath) {
                $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' homedir matches exactly, MATCHED");
                $matchedUsers[] = $user;
                continue;
            }
            
            // Match if upload is in a subdirectory of user's homedir
            if (strpos($targetPath, $homedir . '/') === 0) {
                $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' upload is in their subdirectory, MATCHED");
                $matchedUsers[] = $user;
                continue;
            }
            
            // Match if user's homedir is inside the upload path (user should be notified of uploads to parent folders too)
            if (strpos($homedir, $targetPath . '/') === 0 || $homedir === $targetPath) {
                $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' homedir is under upload path, MATCHED");
                $matchedUsers[] = $user;
                continue;
            }
            
            $this->logger->log("[{$timestamp}] MATCHING: User '{$user->getUsername()}' NOT matched");
        }
        
        return $matchedUsers;
    }

    protected function sendUploadNotification($user, string $uploadPath, array $files): void
    {
        $timestamp = date('Y-m-d H:i:s');
        
        try {
            $this->logger->log("[{$timestamp}] SMTP: Starting email send to {$user->getEmail()}");
            $this->logger->log("[{$timestamp}] SMTP: Host: {$this->smtpConfig['host']}, Port: {$this->smtpConfig['port']}, Encryption: " . ($this->smtpConfig['encryption'] ?? 'none'));
            
            $mail = new PHPMailer(true);
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) use ($timestamp) {
                $this->logger->log("[{$timestamp}] SMTP DEBUG [{$level}]: " . trim($str));
            };

            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'] ?? '';
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpConfig['username'] ?? '';
            $mail->Password = $this->smtpConfig['password'] ?? '';
            $mail->Port = $this->smtpConfig['port'] ?? 587;
            
            $encryption = $this->smtpConfig['encryption'] ?? 'tls';
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $fromEmail = $this->smtpConfig['from_email'] ?? 'noreply@example.com';
            $fromName = $this->smtpConfig['from_name'] ?? 'FileGator';
            
            $this->logger->log("[{$timestamp}] SMTP: From: {$fromEmail} ({$fromName}), To: {$user->getEmail()} ({$user->getName()})");
            
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($user->getEmail(), $user->getName());

            $mail->isHTML(true);
            $mail->Subject = 'New files uploaded to your folder';
            
            $fileList = implode("\n", array_map(function($file) {
                return "- " . htmlspecialchars($file);
            }, $files));
            
            $mail->Body = $this->getEmailBody($user->getName(), $uploadPath, $files);
            $mail->AltBody = "Hello {$user->getName()},\n\nNew files have been uploaded to your folder ({$uploadPath}):\n\n" . 
                             implode("\n", array_map(function($f) { return "- " . $f; }, $files)) . 
                             "\n\nRegards,\nFileGator";

            $this->logger->log("[{$timestamp}] SMTP: Attempting to send email...");
            $startTime = microtime(true);
            
            $mail->send();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->log("[{$timestamp}] SMTP: SUCCESS! Email sent to {$user->getEmail()} in {$duration}ms");
            
        } catch (Exception $e) {
            $this->logger->log("[{$timestamp}] SMTP: FAILED to send email to {$user->getEmail()}");
            $this->logger->log("[{$timestamp}] SMTP: Error: " . $e->getMessage());
            if (isset($mail)) {
                $this->logger->log("[{$timestamp}] SMTP: ErrorInfo: " . $mail->ErrorInfo);
            }
        }
    }

    protected function getEmailBody(string $userName, string $uploadPath, array $files): string
    {
        $fileListHtml = implode('', array_map(function($file) {
            return '<li>' . htmlspecialchars($file) . '</li>';
        }, $files));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #3bafbf; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .file-list { background-color: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .file-list ul { margin: 0; padding-left: 20px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FileGator Notification</h1>
        </div>
        <div class="content">
            <p>Hello <strong>{$userName}</strong>,</p>
            <p>New files have been uploaded to your folder:</p>
            <p><strong>Folder:</strong> {$uploadPath}</p>
            <div class="file-list">
                <p><strong>Uploaded files:</strong></p>
                <ul>
                    {$fileListHtml}
                </ul>
            </div>
        </div>
        <div class="footer">
            <p>This is an automated notification from FileGator.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
