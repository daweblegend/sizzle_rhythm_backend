<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config/global.php';
require_once APP_ROOT . '/Config/database.php';

// Load environment variables from .env if not already loaded
if (!isset($_ENV['APP_URL'])) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class MailHandler {
    
    // Base email template (without hero image)
    private static $baseTemplate = '
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{subject}}</title>
    
    <style>
        body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        body, table, td, a { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        
        @media screen and (max-width: 600px) {
            .mobile-padding { padding: 16px !important; }
            .mobile-full-width { width: 100% !important; display: block !important; }
            .mobile-hide { display: none !important; }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-logo { display: none !important; }
            .light-logo { display: inline-block !important; }
        }
        
        .light-logo { display: none; }
        
        .button {
            background-color: #C12928;
            border-radius: 8px;
            color: #ffffff;
            display: inline-block;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size: 16px;
            font-weight: bold;
            line-height: 48px;
            text-align: center;
            text-decoration: none;
            padding: 0 32px;
            -webkit-text-size-adjust: none;
        }
        .button:hover { background-color: #A01E1D; }
    </style>
</head>

<body style="margin: 0; padding: 0; background-color: #F8F8F8; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <center style="width: 100%; background-color: #F8F8F8;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 auto; background-color: #F8F8F8;">
            
            <!-- Header Section -->
            <tr>
                <td style="padding: 40px 0 20px 0;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="margin: 0 auto;">
                        <tr>
                            <td style="text-align: center;">
                                <a href="#" style="text-decoration: none;">
                                    <!-- Dark logo for light mode -->
                                    <img src="https://ik.imagekit.io/kairong/default/logo.png" alt="{{company}}" width="150" height="auto" border="0" class="dark-logo" style="display: inline-block; max-width: 150px; height: auto; outline: none; text-decoration: none; border: 0;">
                                    <!-- Light logo for dark mode -->
                                    <img src="https://ik.imagekit.io/kairong/default/logo-light-2.png" alt="{{company}}" width="150" height="auto" border="0" class="light-logo" style="display: none; max-width: 150px; height: auto; outline: none; text-decoration: none; border: 0;">
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            
            <!-- Main Content Section -->
            <tr>
                <td style="padding: 0;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="margin: 0 auto; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);">
                        {{hero_image}}
                        <!-- Content Area -->
                        <tr>
                            <td class="mobile-padding" style="padding: 40px;">
                                {{body}}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            
            <!-- Social Media Section -->
            <tr>
                <td style="padding: 32px 0 16px 0;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="margin: 0 auto;">
                        <tr>
                            <td style="text-align: center;">
                                <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px;">Connect with us</p>
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto;">
                                    <tr>
                                        <td style="padding: 0 8px;"><a href="https://facebook.com/waves.ng" style="text-decoration: none; color: #C12928;">Facebook</a></td>
                                        <td style="padding: 0 8px;"><a href="https://twitter.com/kairo_ng" style="text-decoration: none; color: #C12928;">Twitter</a></td>
                                        <td style="padding: 0 8px;"><a href="https://instagram.com/waves.ng" style="text-decoration: none; color: #C12928;">Instagram</a></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            
            <!-- Footer Section -->
            <tr>
                <td style="padding: 16px 0 40px 0;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="margin: 0 auto;">
                        <tr>
                            <td style="padding: 20px 16px; text-align: center; border-top: 1px solid #E6E0DB;">
                                <p style="margin: 0 0 12px 0; font-size: 14px; line-height: 1.6;">
                                    <a href="https://waves.ng/help" style="color: #8a7560; text-decoration: none; margin: 0 8px;">Help Center</a>
                                    <span style="color: #E6E0DB;">|</span>
                                    <a href="https://waves.ng/contact" style="color: #8a7560; text-decoration: none; margin: 0 8px;">Contact Us</a>
                                    <span style="color: #E6E0DB;">|</span>
                                    <a href="https://waves.ng/privacy" style="color: #8a7560; text-decoration: none; margin: 0 8px;">Privacy Policy</a>
                                </p>
                                <p style="margin: 0 0 12px 0; color: #8a7560; font-size: 12px; line-height: 1.5;">
                                    {{company}}<br>
                                    123 Perfect Moment Street, Victoria Island<br>
                                    Lagos, Nigeria
                                </p>
                                <p style="margin: 0; color: #8a7560; font-size: 12px; line-height: 1.5;">
                                    &copy; {{year}} {{company}}. All rights reserved.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
    ';
    
    // Hero image section for welcome emails
    private static $heroImage = '
                        <tr>
                            <td style="padding: 0;">
                                <img src="https://images.unsplash.com/photo-1549298916-b41d501d3772?w=1200&h=400&fit=crop" alt="Perfect Moments" width="600" height="auto" border="0" style="width: 100%; max-width: 600px; height: auto; display: block; outline: none; text-decoration: none; border: 0;">
                            </td>
                        </tr>
    ';
    
    /**
     * Get SMTP configuration directly from .env file
     * 
     * @return array SMTP configuration
     * @throws Exception if configuration cannot be loaded
     */
    public static function getSmtpConfig() {
        $config = [
            // Support both SMTP_* (primary) and MAIL_* (fallback) env key naming
            'host'       => $_ENV['SMTP_HOST']       ?? $_ENV['MAIL_HOST'] ?? '',
            'port'       => (int)($_ENV['SMTP_PORT']  ?? $_ENV['MAIL_PORT'] ?? 587),
            'user'       => $_ENV['SMTP_USERNAME']    ?? $_ENV['MAIL_USER'] ?? '',
            'pass'       => $_ENV['SMTP_PASSWORD']    ?? $_ENV['MAIL_PASS'] ?? '',
            'from_email' => $_ENV['SMTP_FROM_EMAIL']  ?? $_ENV['SMTP_USERNAME'] ?? $_ENV['MAIL_USER'] ?? '',
            'from_name'  => $_ENV['SMTP_FROM_NAME']   ?? $_ENV['SITE_NAME'] ?? 'Sizzle & Rhythm',
            'company'    => $_ENV['SITE_NAME']        ?? 'Sizzle & Rhythm',
        ];
        // Validate required config
        if (empty($config['host']) || empty($config['user']) || empty($config['pass'])) {
            throw new Exception('SMTP configuration is incomplete. Check SMTP_HOST, SMTP_USERNAME and SMTP_PASSWORD in .env');
        }
        return $config;
    }
    
    /**
     * Compile template with variables
     * 
     * @param string $template Template string
     * @param array $variables Variables to replace
     * @return string Compiled template
     */
    private static function compileTemplate($template, $variables) {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
    
    /**
     * Function to send a transactional email.
     * 
     * Delivery mode is controlled by the MAIL_DELIVERY_MODE env variable:
     *   - "direct" (default) → sends immediately via SMTP
     *   - "queue"            → queues the email for async processing
     * 
     * @param string $email Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param bool $includeHero Whether to include hero image (default: false)
     * @param int $priority Email priority (1=highest, 10=lowest, default: 5)
     * @return array Result with success status
     */
    public static function sendTransactionalEmail($email, $subject, $body, $includeHero = false, $priority = 5) {
        $mode = strtolower(trim($_ENV['MAIL_DELIVERY_MODE'] ?? 'direct'));

        if ($mode === 'queue') {
            // Queue the email for asynchronous processing
            require_once __DIR__ . '/EmailQueueHandler.php';
            
            $result = EmailQueueHandler::queueEmail($email, $subject, $body, $includeHero, $priority);
            
            if ($result['success']) {
                error_log("📧 Email queued for async processing - To: $email, Subject: $subject, Queue ID: " . $result['queue_id']);
            } else {
                error_log("❌ Failed to queue email - To: $email, Subject: $subject, Error: " . ($result['error'] ?? 'Unknown error'));
            }
            
            return $result;
        }

        // Default: send immediately
        $result = self::sendTransactionalEmailNow($email, $subject, $body, $includeHero);

        if ($result['success']) {
            error_log("📧 Email sent directly - To: $email, Subject: $subject");
        } else {
            error_log("❌ Failed to send email directly - To: $email, Subject: $subject, Error: " . ($result['error'] ?? 'Unknown error'));
        }

        return $result;
    }

    /**
     * Function to actually send a transactional email (used by email processor)
     * 
     * @param string $email Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param bool $includeHero Whether to include hero image (default: false)
     * @return array Result with success status
     */
    public static function sendTransactionalEmailNow($email, $subject, $body, $includeHero = false) {
        try {
            $config = self::getSmtpConfig();
            
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['user'];
            $mail->Password = $config['pass'];
            $mail->SMTPSecure = $config['port'] == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $config['port'];
            
            // Set UTF-8 encoding for emojis and special characters
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = 'base64';
            
            // Recipients - use dedicated from email/name instead of SMTP username
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            
            // Compile the template
            $html = self::compileTemplate(self::$baseTemplate, [
                'subject' => $subject,
                'body' => $body,
                'hero_image' => $includeHero ? self::$heroImage : '',
                'year' => date('Y'),
                'company' => $config['company']
            ]);
            
            $mail->Body = $html;
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
            
        } catch (Exception $e) {
            error_log('❌ Email sending failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Function to send OTP via email
     * 
     * @param string $email Recipient email
     * @param string $otp OTP code
     * @throws Exception if email sending fails
     */
    public static function sendOTPViaEmail($email, $otp) {
        $subject = 'Your Verification Code';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Verify Your Account
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Please use the verification code below to complete your action:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
                <tr>
                    <td style="padding: 32px; text-align: center;">
                        <div style="font-size: 36px; font-weight: bold; color: #C12928; letter-spacing: 8px; font-family: monospace;">
                            ' . htmlspecialchars($otp) . '
                        </div>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                <strong>Important:</strong> This code will expire in 10 minutes. Please do not share this code with anyone.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                If you didn\'t request this code, please ignore this email or contact our support team.
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false, $priority = 1, $immediate = true);
    }
    
    /**
     * Function to send a welcome email
     * 
     * @param string $email Recipient email
     * @param string $name User name
     * @throws Exception if email sending fails
     */
    public static function sendWelcomeEmail($email, $name) {
        // Extract first name from full name
        $firstName = explode(' ', trim($name))[0];
        
        $subject = 'Welcome to Sizzle & Rhythms!';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Hi ' . htmlspecialchars($firstName) . '!
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Welcome to Sizzle & Rhythms! We\'re thrilled to have you join our community of gift-givers and celebration enthusiasts.
            </p>
            
            <p style="margin: 0 0 24px 0; color: #8a7560; font-size: 16px; line-height: 1.6;">
                Perfect moments deserve perfect gifts, and we\'re here to make sure yours is unforgettable.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
                <tr>
                    <td style="padding: 24px;">
                        <h2 style="margin: 0 0 16px 0; color: #181411; font-size: 18px; font-weight: bold;">
                            What You Can Do Now:
                        </h2>
                        <ul style="margin: 0; padding-left: 20px; color: #8a7560; font-size: 14px; line-height: 2;">
                            <li>Explore our curated collection of gifts</li>
                            <li>Create your wishlist for special occasions</li>
                            <li>Send gifts to your loved ones</li>
                            <li>Track your orders in real-time</li>
                        </ul>
                    </td>
                </tr>
            </table>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="https://waves.ng/shop" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Start Shopping
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 8px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                Need help? Our customer support team is always ready to assist you.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                With love,<br>
                <strong>The Waves Team</strong>
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, true); // Include hero image
    }
    
    /**
     * Function to send a transaction alert email
     * 
     * @param string $email Recipient email
     * @param array $transactionDetails Transaction details
     * @throws Exception if email sending fails
     */
    public static function sendTransactionAlertEmail($email, $transactionDetails) {
        $subject = 'Transaction Alert';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Transaction Notification
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                A transaction has been processed on your account.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
                <tr>
                    <td style="padding: 24px;">
                        <h2 style="margin: 0 0 16px 0; color: #181411; font-size: 18px; font-weight: bold;">
                            Transaction Details
                        </h2>
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                            <tr>
                                <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Amount:</td>
                                <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: 600; text-align: right;">
                                    ' . htmlspecialchars($transactionDetails['amount'] ?? 'N/A') . '
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Reference:</td>
                                <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: 600; text-align: right;">
                                    ' . htmlspecialchars($transactionDetails['ref'] ?? 'N/A') . '
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Date:</td>
                                <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: 600; text-align: right;">
                                    ' . htmlspecialchars($transactionDetails['date'] ?? 'N/A') . '
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Service:</td>
                                <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: 600; text-align: right;">
                                    ' . htmlspecialchars($transactionDetails['service'] ?? 'N/A') . '
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #8a7560; font-size: 14px; border-top: 1px solid #E6E0DB;">Status:</td>
                                <td style="padding: 8px 0; color: #28a745; font-size: 14px; font-weight: bold; text-align: right; border-top: 1px solid #E6E0DB;">
                                    ' . htmlspecialchars($transactionDetails['status'] ?? 'N/A') . '
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 8px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                If you did not authorize this transaction, please contact our support team immediately.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Thank you,<br>
                <strong>The Waves Team</strong>
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false);
    }
    
    /**
     * Function to send a forgot password email
     * 
     * @param string $email Recipient email
     * @param string $resetLink Password reset link
     * @throws Exception if email sending fails
     */
    public static function sendForgotPasswordEmail($email, $resetLink) {
        $subject = 'Password Reset Request';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Reset Your Password
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                We received a request to reset your password. Click the button below to create a new password:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="' . htmlspecialchars($resetLink) . '" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Reset Password
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                If the button doesn\'t work, copy and paste this link into your browser:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 16px;">
                        <p style="margin: 0; word-break: break-all; color: #8a7560; font-size: 12px;">
                            ' . htmlspecialchars($resetLink) . '
                        </p>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #dc3545; font-size: 14px; line-height: 1.6;">
                <strong>Important:</strong> This link will expire in 1 hour for security reasons.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                If you didn\'t request this password reset, please ignore this email or contact our support team if you have concerns.
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false, $priority = 1, $immediate = true);
    }
    
    /**
     * Reset password by admin
     * 
     * @param string $email Recipient email
     * @param string $password New password
     * @throws Exception if email sending fails
     */
    public static function sendAdminPasswordResetEmail($email, $password) {
        $subject = 'Your Password Has Been Reset';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Password Reset by Admin
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Your password has been reset by an administrator. Please find your new temporary password below:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
                <tr>
                    <td style="padding: 24px; text-align: center;">
                        <p style="margin: 0 0 8px 0; color: #8a7560; font-size: 14px;">Your New Password:</p>
                        <div style="font-size: 24px; font-weight: bold; color: #C12928; letter-spacing: 2px; font-family: monospace; padding: 16px; background-color: #ffffff; border-radius: 8px; border: 2px dashed #E6E0DB;">
                            ' . htmlspecialchars($password) . '
                        </div>
                    </td>
                </tr>
            </table>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 16px;">
                        <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.6;">
                            <strong>Security Notice:</strong> Please change this password immediately after logging in for security reasons.
                        </p>
                    </td>
                </tr>
            </table>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="https://waves.ng/login" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Login Now
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                If you did not request this password reset, please contact our support team immediately.
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false);
    }
    
    /**
     * Send email verification link
     * 
     * @param string $email Recipient email
     * @param string $verificationLink Email verification link
     * @throws Exception if email sending fails
     */
    public static function sendEmailVerification($email, $verificationLink) {
        $subject = 'Verify Your Email Address';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Verify Your Email
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Thank you for registering with us! We\'re almost there. Please verify your email address to complete your registration.
            </p>
            
            <p style="margin: 0 0 24px 0; color: #8a7560; font-size: 16px; line-height: 1.6;">
                Click the button below to verify your email:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="' . htmlspecialchars($verificationLink) . '" class="button" style="background-color: #28a745; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Verify Email Address
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                If the button doesn\'t work, copy and paste this link into your browser:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 16px;">
                        <p style="margin: 0; word-break: break-all; color: #8a7560; font-size: 12px;">
                            ' . htmlspecialchars($verificationLink) . '
                        </p>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                <strong>Note:</strong> This verification link will expire in 24 hours.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                If you didn\'t create an account with us, please ignore this email.
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false, $priority = 1, $immediate = true);
    }
    
    /**
     * Send account activation email
     * 
     * @param string $email Recipient email
     * @param string $name User name
     * @throws Exception if email sending fails
     */
    public static function sendAccountActivationEmail($email, $name) {
        $subject = 'Account Activated Successfully';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Welcome Aboard, ' . htmlspecialchars($name) . '!
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Great news! Your account has been successfully activated and verified.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #d4edda; border-left: 4px solid #28a745; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 16px;">
                        <p style="margin: 0; color: #155724; font-size: 14px; line-height: 1.6;">
                            <strong>Account Status:</strong> Active and Ready
                        </p>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 24px 0; color: #8a7560; font-size: 16px; line-height: 1.6;">
                You can now enjoy full access to all our services and features.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="https://waves.ng/dashboard" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Go to Dashboard
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 8px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                If you have any questions or need assistance, our support team is here to help.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Best regards,<br>
                <strong>The Waves Team</strong>
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false);
    }
    
    /**
     * Send low balance alert email
     * 
     * @param string $email Recipient email
     * @param string $name User name
     * @param string $currentBalance Current account balance
     * @throws Exception if email sending fails
     */
    public static function sendLowBalanceAlert($email, $name, $currentBalance) {
        $subject = 'Low Balance Alert';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Balance Alert
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Hello <strong>' . htmlspecialchars($name) . '</strong>,
            </p>
            
            <p style="margin: 0 0 24px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                This is a friendly reminder that your account balance is running low.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 24px;">
                        <p style="margin: 0 0 8px 0; color: #856404; font-size: 14px;">Current Balance:</p>
                        <p style="margin: 0; color: #856404; font-size: 32px; font-weight: bold;">
                            ' . htmlspecialchars($currentBalance) . '
                        </p>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 24px 0; color: #8a7560; font-size: 16px; line-height: 1.6;">
                To avoid any service interruptions, please top up your account at your earliest convenience.
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="https://waves.ng/wallet/fund" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Fund Account
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
                You can fund your account through various payment methods available in your dashboard.
            </p>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Thank you,<br>
                <strong>The Waves Team</strong>
            </p>
        ';
        
        self::sendTransactionalEmail($email, $subject, $body, false);
    }
    
    /**
     * Function to send password reset email
     * 
     * @param string $email Recipient email
     * @param string $name User name
     * @param string $newPassword New temporary password
     * @throws Exception if email sending fails
     */
    public static function sendPasswordResetEmail($email, $name, $newPassword) {
        $subject = 'Password Reset - Waves';
        $body = '
            <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
                Password Reset Confirmation
            </h1>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Hello <strong>' . htmlspecialchars($name) . '</strong>,
            </p>
            
            <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
                Your password has been reset by an administrator. Your new temporary password is:
            </p>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
                <tr>
                    <td style="padding: 24px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #C12928; letter-spacing: 2px; font-family: monospace; padding: 20px; background-color: #ffffff; border-radius: 8px; border: 2px dashed #E6E0DB;">
                            ' . htmlspecialchars($newPassword) . '
                        </div>
                    </td>
                </tr>
            </table>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #f8d7da; border-left: 4px solid #dc3545; border-radius: 8px; overflow: hidden;">
                <tr>
                    <td style="padding: 16px;">
                        <p style="margin: 0; color: #721c24; font-size: 14px; line-height: 1.6;">
                            <strong>Important Security Notice:</strong> Please log in and change this password immediately for security reasons.
                        </p>
                    </td>
                </tr>
            </table>
            
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
                <tr>
                    <td>
                        <a href="https://waves.ng/login" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                            Login Now
                        </a>
                    </td>
                </tr>
            </table>
            
            <p style="margin: 0; color: #181411; font-size: 16px; line-height: 1.6;">
                If you did not request this password reset, please contact our support team immediately.<br><br>
                Thank you,<br>
                <strong>Waves Team</strong>
            </p>
        ';
        
        $result = self::sendTransactionalEmail($email, $subject, $body, false);
        
        if (!$result['success']) {
            throw new Exception('Failed to send password reset email: ' . $result['error']);
        }
        
        return $result;
    }
    
    /**
     * Generic email sending function that can be used for any purpose
     * 
     * @param string $email Recipient email address
     * @param string $subject Email subject line
     * @param string $body Email body content (can be HTML)
     * @param array $attachments Optional array of attachment file paths
     * @return array Success status and error message if failed
     */
    public static function sendEmail($email, $subject, $body, $attachments = []) {
        try {
            return self::sendTransactionalEmail($email, $subject, $body);
        } catch (Exception $e) {
            error_log('Failed to send email: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send gift claimed notification to recipient (who just registered)
     * 
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param array $gifts Array of claimed gifts
     * @param float $totalMoney Total money claimed
     * @return array Success status
     */
    public static function sendGiftClaimedRecipientEmail($email, $name, $gifts, $totalMoney) {
        $appUrl = $_ENV['APP_URL'] ?? 'https://waves.ng';
        $dashboardUrl = $appUrl . '/dashboard';
        
        $giftCount = count($gifts);
        $giftWord = $giftCount === 1 ? 'gift' : 'gifts';
        
        // Build gift list HTML
        $giftListHtml = '';
        foreach ($gifts as $gift) {
            $giftType = ucfirst($gift['type']);
            $amountDisplay = $gift['type'] === 'money' ? '₦' . number_format($gift['amount'], 2) : '';
            
            $giftListHtml .= '<tr>
                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                    <strong style="color: #111827;">' . $giftType . ' Gift</strong>
                </td>
                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                    <span style="color: #059669; font-weight: 600;">' . $amountDisplay . '</span>
                </td>
            </tr>';
        }
        
        // Build wallet credit message
        $walletMessage = '';
        if ($totalMoney > 0) {
            $walletMessage = '<div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 20px; border-radius: 12px; margin: 24px 0; text-align: center;">
                <div style="color: white; font-size: 14px; margin-bottom: 8px;">Your Wallet Has Been Credited</div>
                <div style="color: white; font-size: 32px; font-weight: bold;">₦' . number_format($totalMoney, 2) . '</div>
            </div>';
        }
        
        $body = '
            <div style="text-align: center; margin-bottom: 32px;">
                <div style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                    <span style="font-size: 40px;">🎁</span>
                </div>
                <h1 style="color: #111827; font-size: 28px; font-weight: bold; margin: 0 0 12px 0;">
                    Welcome to Kairo, ' . htmlspecialchars($name) . '!
                </h1>
                <p style="color: #6b7280; font-size: 16px; margin: 0;">
                    Great news! You have ' . $giftCount . ' ' . $giftWord . ' waiting for you
                </p>
            </div>
            
            ' . $walletMessage . '
            
            <div style="background: #f9fafb; border-radius: 12px; padding: 24px; margin: 24px 0;">
                <h2 style="color: #111827; font-size: 18px; margin: 0 0 16px 0; font-weight: 600;">
                    Your Claimed Gifts
                </h2>
                <table style="width: 100%; border-collapse: collapse;">
                    ' . $giftListHtml . '
                </table>
            </div>
            
            <div style="text-align: center; margin: 32px 0;">
                <a href="' . $dashboardUrl . '" 
                   style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
                          color: white; text-decoration: none; padding: 16px 32px; border-radius: 8px; 
                          font-weight: 600; font-size: 16px;">
                    View Your Dashboard
                </a>
            </div>
            
            <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 8px; margin: 24px 0;">
                <p style="color: #1e40af; margin: 0; font-size: 14px;">
                    <strong>💡 What\'s Next?</strong><br>
                    • Your money gifts have been automatically added to your wallet<br>
                    • Product gifts will be processed and delivered to your registered address<br>
                    • You can track all your gifts in your dashboard
                </p>
            </div>
        ';
        
        $subject = '🎁 Your ' . $giftWord . ' ' . ($giftCount === 1 ? 'has' : 'have') . ' been claimed!';
        
        $result = self::sendTransactionalEmail($email, $subject, $body, true);
        
        if (!$result['success']) {
            throw new Exception('Failed to send gift claimed recipient email: ' . $result['error']);
        }
        
        return $result;
    }
    
    /**
     * Send gift claimed notification to sender
     * 
     * @param string $email Sender email
     * @param string $senderName Sender name
     * @param string $recipientName Recipient name
     * @param array $gift Gift details
     * @return array Success status
     */
    public static function sendGiftClaimedSenderEmail($email, $senderName, $recipientName, $gift) {
        $appUrl = $_ENV['APP_URL'] ?? 'https://waves.ng';
        $dashboardUrl = $appUrl . '/dashboard/gifts';
        
        $giftType = ucfirst($gift['type']);
        $amountDisplay = $gift['type'] === 'money' ? '₦' . number_format($gift['amount'], 2) : '';
        $message = isset($gift['message']) && $gift['message'] ? $gift['message'] : '';
        
        $messageHtml = '';
        if ($message) {
            $messageHtml = '<div style="background: #f9fafb; border-radius: 8px; padding: 16px; margin: 20px 0;">
                <p style="color: #6b7280; font-size: 14px; margin: 0 0 8px 0;">Your Message:</p>
                <p style="color: #111827; font-style: italic; margin: 0;">"' . htmlspecialchars($message) . '"</p>
            </div>';
        }
        
        $body = '
            <div style="text-align: center; margin-bottom: 32px;">
                <div style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                    <span style="font-size: 40px;">✅</span>
                </div>
                <h1 style="color: #111827; font-size: 28px; font-weight: bold; margin: 0 0 12px 0;">
                    Your Gift Has Been Claimed!
                </h1>
                <p style="color: #6b7280; font-size: 16px; margin: 0;">
                    ' . htmlspecialchars($recipientName) . ' just claimed the gift you sent
                </p>
            </div>
            
            <div style="background: #f9fafb; border-radius: 12px; padding: 24px; margin: 24px 0;">
                <table style="width: 100%;">
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb;">
                            <span style="color: #6b7280; font-size: 14px;">Recipient</span>
                        </td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; text-align: right;">
                            <strong style="color: #111827;">' . htmlspecialchars($recipientName) . '</strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb;">
                            <span style="color: #6b7280; font-size: 14px;">Gift Type</span>
                        </td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; text-align: right;">
                            <strong style="color: #111827;">' . $giftType . '</strong>
                        </td>
                    </tr>
                    ' . ($amountDisplay ? '<tr>
                        <td style="padding: 12px 0;">
                            <span style="color: #6b7280; font-size: 14px;">Amount</span>
                        </td>
                        <td style="padding: 12px 0; text-align: right;">
                            <strong style="color: #059669; font-size: 18px;">' . $amountDisplay . '</strong>
                        </td>
                    </tr>' : '') . '
                </table>
                
                ' . $messageHtml . '
            </div>
            
            <div style="background: #ecfdf5; border-radius: 12px; padding: 20px; margin: 24px 0; text-align: center;">
                <p style="color: #065f46; margin: 0; font-size: 16px;">
                    🎉 <strong>' . htmlspecialchars($recipientName) . '</strong> has successfully claimed your gift!<br>
                    <span style="font-size: 14px;">They can now enjoy what you sent them.</span>
                </p>
            </div>
            
            <div style="text-align: center; margin: 32px 0;">
                <a href="' . $dashboardUrl . '" 
                   style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
                          color: white; text-decoration: none; padding: 16px 32px; border-radius: 8px; 
                          font-weight: 600; font-size: 16px;">
                    View Gift History
                </a>
            </div>
            
            <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 8px; margin: 24px 0;">
                <p style="color: #1e40af; margin: 0; font-size: 14px;">
                    <strong>💝 Spread the Joy!</strong><br>
                    Your thoughtful gift has made someone\'s day. Want to send more gifts? Visit your dashboard to get started!
                </p>
            </div>
        ';
        
        $subject = '✅ ' . $recipientName . ' claimed your ' . $giftType . ' gift!';
        
        $result = self::sendTransactionalEmail($email, $subject, $body, true);
        
        if (!$result['success']) {
            throw new Exception('Failed to send gift claimed sender email: ' . $result['error']);
        }
        
        return $result;
    }
}