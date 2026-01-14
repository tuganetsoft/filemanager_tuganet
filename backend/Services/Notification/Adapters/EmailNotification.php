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
        if (empty($this->smtpConfig['enabled']) || empty($files)) {
            return;
        }

        $users = $this->findUsersForPath($uploadPath);
        
        if (empty($users)) {
            return;
        }

        foreach ($users as $user) {
            if (empty($user->getEmail())) {
                continue;
            }
            
            $this->sendUploadNotification($user, $uploadPath, $files);
        }
    }

    protected function findUsersForPath(string $uploadPath): array
    {
        $matchedUsers = [];
        $allUsers = $this->auth->allUsers();
        
        $targetPath = '/' . trim($uploadPath, '/');
        if ($targetPath !== '/') {
            $targetPath = rtrim($targetPath, '/');
        }
        
        foreach ($allUsers as $user) {
            $homedir = $user->getHomeDir();
            if (empty($homedir)) {
                continue;
            }
            
            $homedir = '/' . trim($homedir, '/');
            if ($homedir !== '/') {
                $homedir = rtrim($homedir, '/');
            }
            
            if ($homedir === '/') {
                $matchedUsers[] = $user;
                continue;
            }
            
            if ($homedir === $targetPath) {
                $matchedUsers[] = $user;
                continue;
            }
            
            if (strpos($targetPath, $homedir . '/') === 0) {
                $matchedUsers[] = $user;
            }
        }
        
        return $matchedUsers;
    }

    protected function sendUploadNotification($user, string $uploadPath, array $files): void
    {
        try {
            $mail = new PHPMailer(true);

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
            }

            $fromEmail = $this->smtpConfig['from_email'] ?? 'noreply@example.com';
            $fromName = $this->smtpConfig['from_name'] ?? 'FileGator';
            
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

            $mail->send();
            
            $this->logger->log("Email notification sent to {$user->getEmail()} for upload to {$uploadPath}");
            
        } catch (Exception $e) {
            $this->logger->log("Failed to send email notification to {$user->getEmail()}: " . $e->getMessage());
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
