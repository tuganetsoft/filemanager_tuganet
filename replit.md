# FileGator

## Overview

FileGator is a free, open-source, self-hosted web application for managing files and folders. It's a powerful file manager with a clean and responsive interface.

## Default Credentials

- **Username:** admin
- **Password:** admin123

## Project Structure

- `backend/` - PHP backend code
- `frontend/` - Vue.js frontend source code
- `dist/` - Built frontend assets
- `private/` - Private data (users, sessions, logs)
- `repository/` - Default file storage directory
- `configuration.php` - Main configuration file
- `server.php` - PHP server router for development

## Running the Application

The application runs on port 5000 using PHP's built-in server:
```
php -S 0.0.0.0:5000 server.php
```

## Development

### Building the Frontend

```bash
npm install
npm run build
```

### PHP Dependencies

```bash
composer install
```

## Configuration

Edit `configuration.php` to customize:
- App name and logo
- Upload limits
- Authentication method
- Storage adapter
- Session handling
- SMTP email settings for notifications

### SMTP Email Configuration

To enable email notifications when files are uploaded, configure the SMTP settings in `configuration.php`:

```php
'smtp' => [
    'enabled' => true, // set to true to enable email notifications
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => 'your-smtp-username',
    'password' => 'your-smtp-password',
    'encryption' => 'tls', // 'tls', 'ssl', or '' for none
    'from_email' => 'noreply@example.com',
    'from_name' => 'FileGator',
],
```

When enabled, users will receive email notifications when files are uploaded to folders that match their home directory.

## Recent Changes

- Initial setup for Replit environment (January 2026)
- Configured PHP server to serve on port 5000
- Built Vue.js frontend assets
- Added email field to user management (January 2026)
- Added SMTP email notifications for file uploads (January 2026)
