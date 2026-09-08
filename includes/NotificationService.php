<?php
// includes/NotificationService.php

if (!defined('TERMII_API_KEY') || !defined('EBULKSMS_API_TOKEN')) {
    $localSecrets = __DIR__ . '/local_secrets.php';
    if (file_exists($localSecrets)) {
        require_once $localSecrets;
    }
}

class NotificationService {
    private $pdo;
    private $smsProvider;
    private $emailFrom;
    private $emailFromName;

    // SMS Gateway Configuration - real keys live in includes/local_secrets.php
    // (gitignored; see includes/local_secrets.sample.php for the template).
    private $smsConfig = [];
    
    // SMTP Email Configuration
    private $smtpConfig = [
        'host' => 'smtp.gmail.com', // Replace with your SMTP host
        'port' => 587,
        'username' => 'your-email@gmail.com', // Replace with your email
        'password' => 'your-app-password', // Replace with your app password
        'encryption' => 'tls'
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;

        $this->smsConfig = [
            'bulk_sms_nigeria' => [
                'api_url' => 'https://api.ebulksms.com/sendsms.json',
                'api_token' => defined('EBULKSMS_API_TOKEN') ? EBULKSMS_API_TOKEN : '',
                'username' => 'wgdagogo.nis@gmail.com',
                'sender_id' => 'NIS-PPMS',
                'route' => 'dnd'
            ],
            'termii' => [
                'api_url' => 'https://v4.api.termii.com/',
                'api_key' => defined('TERMII_API_KEY') ? TERMII_API_KEY : '',
                'sender_id' => 'NIS-POSTING',
                'channel' => 'dnd'
            ],
            'africastalking' => [
                'api_url' => 'https://api.africastalking.com/version1/messaging',
                'username' => 'YOUR_USERNAME', // Replace with actual username
                'api_key' => 'YOUR_API_KEY_HERE' // Replace with actual key
            ]
        ];

        $this->smsProvider = 'termii'; // Active real-time SMS provider
        $this->emailFrom = 'noreply@nis.gov.ng';
        $this->emailFromName = 'NIS Posting Management System';

        // Load settings from database
        $this->loadSettings();
    }
    
    private function loadSettings() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM notification_settings LIMIT 1");
            $settings = $stmt->fetch();
            if ($settings) {
                $this->smsProvider = $settings['sms_provider'] ?? $this->smsProvider;
                $this->emailFrom = $settings['email_from'] ?? $this->emailFrom;
                $this->emailFromName = $settings['email_from_name'] ?? $this->emailFromName;
            }
        } catch (Exception $e) {
            // Use defaults
        }
    }
    
    // ============================================
    // SMS FUNCTIONS
    // ============================================
    
    /**
     * Send SMS notification to officer about posting
     */
    public function sendPostingSMS($serviceNo, $officerName, $newLocation, $postingDate, $phone = null) {
        // Get officer phone if not provided
        if (!$phone) {
            $phone = $this->getOfficerPhone($serviceNo);
        }
        
        if (!$phone || !$this->validateNigerianPhone($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number'];
        }
        
        $message = "Dear {$officerName},\n\n"
                 . "You have been posted to:\n"
                 . "{$newLocation}\n"
                 . "Effective Date: " . date('d/m/Y', strtotime($postingDate)) . "\n\n"
                 . "Please report to your new formation within 30 days.\n\n"
                 . "- NIS Posting Management";
        
        return $this->sendSMS($phone, $message, 'posting_notification', $serviceNo, $officerName);
    }
    
    /**
     * Send reminder SMS to officers who haven't reported
     */
    public function sendReminderSMS($serviceNo, $officerName, $newLocation, $postingDate, $daysSincePosting, $phone = null) {
        if (!$phone) {
            $phone = $this->getOfficerPhone($serviceNo);
        }
        
        if (!$phone || !$this->validateNigerianPhone($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number'];
        }
        
        $message = "REMINDER: Dear {$officerName},\n\n"
                 . "You were posted to {$newLocation} on " . date('d/m/Y', strtotime($postingDate)) . " "
                 . "({$daysSincePosting} days ago).\n\n"
                 . "You are yet to report to your new formation. "
                 . "Please report immediately or contact the posting authority.\n\n"
                 . "- NIS Posting Management";
        
        return $this->sendSMS($phone, $message, 'reminder', $serviceNo, $officerName);
    }
    
    /**
     * Send SMS to formation commander about incoming officer
     */
    public function sendCommanderNotificationSMS($serviceNo, $officerName, $officerRank, $newLocation, $postingDate, $commanderPhone = null) {
        if (!$commanderPhone) {
            // Could look up commander's phone from database
            return ['success' => false, 'message' => 'Commander phone not available'];
        }
        
        if (!$this->validateNigerianPhone($commanderPhone)) {
            return ['success' => false, 'message' => 'Invalid commander phone number'];
        }
        
        $message = "NOTIFICATION: A new officer has been posted to your command.\n\n"
                 . "Officer: {$officerName}\n"
                 . "Rank: {$officerRank}\n"
                 . "NIS No: {$serviceNo}\n"
                 . "Posted to: {$newLocation}\n"
                 . "Date: " . date('d/m/Y', strtotime($postingDate)) . "\n\n"
                 . "Please prepare to receive this officer.\n\n"
                 . "- NIS Posting Management";
        
        return $this->sendSMS($commanderPhone, $message, 'general', $serviceNo, "Commander - {$newLocation}");
    }
    
    /**
     * Send admit confirmation SMS to posting authority
     */
    public function sendAdmitConfirmationSMS($serviceNo, $officerName, $admittedLocation, $phone = null) {
        if (!$phone) {
            $phone = $this->getOfficerPhone($serviceNo);
        }
        
        if (!$phone || !$this->validateNigerianPhone($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number'];
        }
        
        $message = "CONFIRMATION: Officer {$officerName} (NIS No: {$serviceNo}) "
                 . "has been admitted at {$admittedLocation}.\n\n"
                 . "Date: " . date('d/m/Y') . "\n\n"
                 . "- NIS Posting Management";
        
        return $this->sendSMS($phone, $message, 'admit_confirmation', $serviceNo, $officerName);
    }
    
    /**
     * Core SMS sending function
     */
    private function sendSMS($phone, $message, $type, $serviceNo, $recipientName) {
        require_once __DIR__ . '/settings_helper.php';
        if (getSystemSetting('auto_sms_enabled', '1') !== '1') {
            return ['success' => false, 'message' => 'SMS dispatch bypassed: Automated SMS is turned OFF in System Settings.'];
        }

        // Normalize phone number
        $phone = $this->normalizeNigerianPhone($phone);
        
        // Log SMS attempt
        $logId = $this->logSMS($phone, $recipientName, $message, $type, $serviceNo, 'pending');
        
        // Try to send via API
        try {
            $response = $this->sendViaAPI($phone, $message);
            
            if ($response['success']) {
                $this->updateSMSLog($logId, 'sent', $response['response']);
                return ['success' => true, 'message' => 'SMS sent successfully', 'log_id' => $logId];
            } else {
                $this->updateSMSLog($logId, 'failed', $response['response']);
                return ['success' => false, 'message' => $response['response']];
            }
        } catch (Exception $e) {
            $this->updateSMSLog($logId, 'failed', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send SMS via configured API provider
     */
    private function sendViaAPI($phone, $message) {
        $config = $this->smsConfig[$this->smsProvider] ?? $this->smsConfig['bulk_sms_nigeria'];
        
        switch ($this->smsProvider) {
            case 'bulk_sms_nigeria':
                return $this->sendViaBulkSMSNigeria($phone, $message, $config);
            case 'termii':
                return $this->sendViaTermii($phone, $message, $config);
            case 'africastalking':
                return $this->sendViaAfricasTalking($phone, $message, $config);
            default:
                // Fallback: Simulate sending for testing
                return ['success' => true, 'response' => 'SMS sent successfully (simulated)'];
        }
    }
    
            private function sendViaBulkSMSNigeria($phone, $message, $config) {
        $curl = curl_init();
        
        // Normalize phone to international format
        $country_code = '234';
        $mobilenumber = trim($phone);
        if (substr($mobilenumber, 0, 1) == '0') {
            $mobilenumber = $country_code . substr($mobilenumber, 1);
        } elseif (substr($mobilenumber, 0, 1) == '+') {
            $mobilenumber = substr($mobilenumber, 1);
        }
        
        // Generate unique message ID
        $generated_id = uniqid('int_', false);
        $generated_id = substr($generated_id, 0, 30);
        
        // Build the EXACT JSON format ebulksms expects
        $jsonData = json_encode([
            'SMS' => [
                'auth' => [
                    'username' => $config['username'] ?? '',
                    'apikey' => $config['api_token']
                ],
                'message' => [
                    'sender' => $config['sender_id'],
                    'messagetext' => $message,
                    'flash' => '0'
                ],
                'recipients' => [
                    'gsm' => [
                        [
                            'msidn' => $mobilenumber,
                            'msgid' => $generated_id
                        ]
                    ]
                ],
                'dndsender' => 0
            ]
        ]);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.ebulksms.com/sendsms.json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            return ['success' => false, 'response' => 'Curl error: ' . $error];
        }
        
        $result = json_decode($response, true);
        
        // Check ebulksms response
        if ($httpCode === 200 && $result) {
            $status = $result['response']['status'] ?? ($result['status'] ?? '');
            if (strtoupper($status) === 'SUCCESS') {
                return ['success' => true, 'response' => $response];
            }
        }
        
        return ['success' => false, 'response' => $response];
    }
    
    private function sendViaTermii($phone, $message, $config) {
        $curl = curl_init();
        $postData = [
            'api_key' => $config['api_key'],
            'to' => $phone,
            'from' => $config['sender_id'],
            'sms' => $message,
            'type' => 'plain',
            'channel' => $config['channel']
        ];

        // Termii's base URL is just the domain root (returns 404 on its own,
        // confirmed live against v4.api.termii.com) - the actual SMS-send
        // route is /api/sms/send.
        $sendUrl = rtrim($config['api_url'], '/') . '/api/sms/send';

        curl_setopt_array($curl, [
            CURLOPT_URL => $sendUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            return ['success' => true, 'response' => $response];
        }
        
        return ['success' => false, 'response' => $response];
    }
    
    private function sendViaAfricasTalking($phone, $message, $config) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $config['api_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $config['username'],
                'to' => $phone,
                'message' => $message
            ]),
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $config['api_key'],
                'Accept: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6
        ]);
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        $result = json_decode($response, true);
        if (isset($result['SMSMessageData']['Recipients'][0]['status']) && 
            $result['SMSMessageData']['Recipients'][0]['status'] === 'Success') {
            return ['success' => true, 'response' => $response];
        }
        
        return ['success' => false, 'response' => $response];
    }
    
    // ============================================
    // EMAIL FUNCTIONS
    // ============================================
    
    /**
     * Send email notification with posting details
     */
    public function sendPostingEmail($serviceNo, $officerName, $officerRank, $newLocation, $postingDate, $email = null) {
        if (!$email) {
            $email = $this->getOfficerEmail($serviceNo);
        }
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower(trim($serviceNo)) . '@nis.gov.ng';
        }
        
        $subject = "Posting Notification - {$newLocation}";
        
        $body = $this->getEmailTemplate('posting', [
            'officer_name' => $officerName,
            'officer_rank' => $officerRank,
            'service_no' => $serviceNo,
            'new_location' => $newLocation,
            'posting_date' => date('d F, Y', strtotime($postingDate)),
            'report_deadline' => date('d F, Y', strtotime($postingDate . ' +30 days')),
            'current_date' => date('d F, Y')
        ]);
        
        return $this->sendEmail($email, $officerName, $subject, $body, 'posting_notification', $serviceNo);
    }
    
    /**
     * Send reminder email
     */
    public function sendReminderEmail($serviceNo, $officerName, $newLocation, $postingDate, $daysSincePosting, $email = null) {
        if (!$email) {
            $email = $this->getOfficerEmail($serviceNo);
        }
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        
        $subject = "REMINDER: Report to {$newLocation}";
        
        $body = $this->getEmailTemplate('reminder', [
            'officer_name' => $officerName,
            'new_location' => $newLocation,
            'posting_date' => date('d F, Y', strtotime($postingDate)),
            'days_since' => $daysSincePosting,
            'current_date' => date('d F, Y')
        ]);
        
        return $this->sendEmail($email, $officerName, $subject, $body, 'reminder', $serviceNo);
    }
    
    /**
     * Core email sending function
     */
    private function sendEmail($email, $name, $subject, $body, $type, $serviceNo, $attachment = null) {
        require_once __DIR__ . '/settings_helper.php';
        if (getSystemSetting('auto_email_enabled', '1') !== '1') {
            return ['success' => false, 'message' => 'Email dispatch bypassed: Automated Email is turned OFF in System Settings.'];
        }

        // Log email attempt
        $logId = $this->logEmail($email, $name, $subject, $body, $type, $serviceNo, $attachment, 'pending');
        
        // Try to send via SMTP
        try {
            $response = $this->sendViaSMTP($email, $name, $subject, $body, $attachment);
            
            if ($response['success']) {
                $this->updateEmailLog($logId, 'sent', $response['response']);
                return ['success' => true, 'message' => 'Email sent successfully', 'log_id' => $logId];
            } else {
                $this->updateEmailLog($logId, 'failed', $response['response']);
                return ['success' => false, 'message' => $response['response']];
            }
        } catch (Exception $e) {
            $this->updateEmailLog($logId, 'failed', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function sendViaSMTP($toEmail, $toName, $subject, $body, $attachment = null) {
        $host = getSystemSetting('smtp_host', $this->smtpConfig['host'] ?? '');
        $port = intval(getSystemSetting('smtp_port', $this->smtpConfig['port'] ?? 587));
        $username = getSystemSetting('smtp_username', $this->smtpConfig['username'] ?? '');
        $password = getSystemSetting('smtp_password', $this->smtpConfig['password'] ?? '');
        $encryption = getSystemSetting('smtp_encryption', $this->smtpConfig['encryption'] ?? 'tls');

        // Check if real SMTP credentials are configured (System Settings -> Notifications)
        if (!empty($host) && !empty($username) && !empty($password) && strpos($username, 'your-email') === false) {
            $result = $this->sendViaSocketSMTP($host, $port, $username, $password, $encryption, $toEmail, $subject, $body);
            if ($result['success']) {
                return $result;
            }
            // Real SMTP is configured but failed - report that failure honestly rather
            // than silently falling through to mail() and calling it a success.
            error_log("SMTP send failed for {$toEmail}: " . $result['response']);
            return $result;
        }

        // No real SMTP configured - last-resort fallback to the server's local mail().
        // This commonly fails outright or gets spam-filtered on shared hosting without
        // proper SPF/DKIM/PTR records, so its actual return value is what gets reported,
        // not an assumed success.
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->emailFromName} <{$this->emailFrom}>\r\n";
        $headers .= "Reply-To: {$this->emailFrom}\r\n";

        $sent = @mail($toEmail, $subject, $body, $headers);
        if ($sent) {
            return ['success' => true, 'response' => 'Email handed to server mail() - delivery is not guaranteed without SMTP credentials configured in System Settings.'];
        }

        return ['success' => false, 'response' => 'No SMTP credentials configured in System Settings, and the server\'s local mail() rejected the message.'];
    }

    /**
     * Speak raw SMTP over a socket, checking the server's response code after
     * every command instead of assuming success once AUTH succeeds.
     */
    private function sendViaSocketSMTP($host, $port, $username, $password, $encryption, $toEmail, $subject, $body) {
        $socketHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 10);
        if (!$socket) {
            return ['success' => false, 'response' => "Could not connect to {$host}:{$port} - {$errstr} ({$errno})"];
        }

        $read = function() use ($socket) { return fgets($socket, 512); };
        $expect = function($code) use ($read) {
            $line = $read();
            return $line !== false && substr($line, 0, 3) === (string)$code ? $line : false;
        };

        $read(); // greeting
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        $read();

        if ($encryption === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            if (!$expect(220)) { fclose($socket); return ['success' => false, 'response' => 'STARTTLS was rejected by the server']; }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'response' => 'TLS handshake failed'];
            }
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            $read();
        }

        fputs($socket, "AUTH LOGIN\r\n");
        if (!$expect(334)) { fclose($socket); return ['success' => false, 'response' => 'Server did not offer AUTH LOGIN']; }
        fputs($socket, base64_encode($username) . "\r\n");
        if (!$expect(334)) { fclose($socket); return ['success' => false, 'response' => 'SMTP username rejected']; }
        fputs($socket, base64_encode($password) . "\r\n");
        if (!$expect(235)) { fclose($socket); return ['success' => false, 'response' => 'SMTP authentication failed - check smtp_username/smtp_password in System Settings']; }

        fputs($socket, "MAIL FROM: <$username>\r\n");
        if (!$expect(250)) { fclose($socket); return ['success' => false, 'response' => 'MAIL FROM rejected by server']; }
        fputs($socket, "RCPT TO: <$toEmail>\r\n");
        if (!$expect(250)) { fclose($socket); return ['success' => false, 'response' => "RCPT TO rejected by server for {$toEmail}"]; }
        fputs($socket, "DATA\r\n");
        if (!$expect(354)) { fclose($socket); return ['success' => false, 'response' => 'DATA command rejected by server']; }

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->emailFromName} <$username>\r\n";
        $headers .= "To: <$toEmail>\r\n";
        $headers .= "Subject: $subject\r\n\r\n";

        fputs($socket, $headers . $body . "\r\n.\r\n");
        $sent = (bool)$expect(250);
        fputs($socket, "QUIT\r\n");
        fclose($socket);

        if (!$sent) {
            return ['success' => false, 'response' => 'Server did not confirm the message was accepted after DATA'];
        }
        return ['success' => true, 'response' => 'Email sent successfully via SMTP'];
    }
    
    // ============================================
    // META WHATSAPP BUSINESS API FUNCTIONS (1,000 FREE MSGS / MONTH)
    // ============================================
    
    /**
     * Send Meta WhatsApp Notification to officer about posting
     */
    public function sendPostingWhatsApp($serviceNo, $officerName, $officerRank, $newLocation, $postingDate, $phone = null) {
        if (!$phone) {
            $phone = $this->getOfficerPhone($serviceNo);
        }
        
        if (!$phone || !$this->validateNigerianPhone($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number for WhatsApp'];
        }
        
        $message = "🏛️ *NIGERIA IMMIGRATION SERVICE (NIS-PPMS)*\n"
                 . "━━━━━━━━━━━━━━━━━━━━\n"
                 . "📋 *OFFICIAL POSTING NOTIFICATION*\n\n"
                 . "Dear *{$officerName}* ({$officerRank}),\n\n"
                 . "You have been officially posted to:\n"
                 . "📍 *{$newLocation}*\n"
                 . "📅 Effective Date: *" . date('d F, Y', strtotime($postingDate)) . "*\n"
                 . "⏳ Reporting Deadline: *" . date('d F, Y', strtotime($postingDate . ' +30 days')) . "*\n\n"
                 . "⚠️ *Notice:* You are required to report to your new formation within 30 days. Failure to report may result in disciplinary action.\n\n"
                 . "🔗 _NIS Personnel Posting Management System_";
        
        return $this->sendWhatsApp($phone, $message, 'posting_notification', $serviceNo, $officerName);
    }
    
    /**
     * Send Reminder WhatsApp
     */
    public function sendReminderWhatsApp($serviceNo, $officerName, $newLocation, $postingDate, $daysSincePosting, $phone = null) {
        if (!$phone) {
            $phone = $this->getOfficerPhone($serviceNo);
        }
        
        if (!$phone || !$this->validateNigerianPhone($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number for WhatsApp'];
        }
        
        $message = "🚨 *NIS POSTING REPORTING REMINDER*\n"
                 . "━━━━━━━━━━━━━━━━━━━━\n"
                 . "Dear *{$officerName}*,\n\n"
                 . "Our records indicate you were posted to *{$newLocation}* on *" . date('d/m/Y', strtotime($postingDate)) . "* ({$daysSincePosting} days ago).\n\n"
                 . "⚠️ You are yet to report to your new formation. Please report immediately or contact Service HQ.\n\n"
                 . "- _NIS Posting Management_";
        
        return $this->sendWhatsApp($phone, $message, 'reminder', $serviceNo, $officerName);
    }

    /**
     * Core Meta WhatsApp API Dispatcher
     */
    private function sendWhatsApp($phone, $message, $type, $serviceNo, $recipientName) {
        require_once __DIR__ . '/settings_helper.php';
        if (getSystemSetting('whatsapp_enabled', '0') !== '1') {
            return ['success' => false, 'message' => 'WhatsApp dispatch bypassed: WhatsApp is turned OFF in System Settings.'];
        }

        $phone = $this->normalizeNigerianPhone($phone);
        $logId = $this->logWhatsApp($phone, $recipientName, $message, $type, $serviceNo, 'pending');

        $phoneId = getSystemSetting('whatsapp_phone_number_id', '');
        $token = getSystemSetting('whatsapp_access_token', '');
        $version = getSystemSetting('whatsapp_api_version', 'v18.0');

        if (empty($phoneId) || empty($token) || $phoneId === '109823475928374' || strpos($token, 'MOCK') !== false) {
            $this->updateWhatsAppLog($logId, 'failed', 'Meta WhatsApp credentials (Phone Number ID / Access Token) not configured in System Settings.');
            return ['success' => false, 'message' => 'WhatsApp dispatch bypassed: Meta WhatsApp credentials not configured in System Settings.'];
        }

        $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $phone,
            "type" => "text",
            "text" => [
                "preview_url" => true,
                "body" => $message
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->updateWhatsAppLog($logId, 'failed', "cURL Error: " . $error);
            return ['success' => false, 'message' => "cURL Error: " . $error];
        }

        $result = json_decode($response, true);
        if (($httpCode === 200 || $httpCode === 201) && isset($result['messages'][0]['id'])) {
            $metaMsgId = $result['messages'][0]['id'];
            $this->updateWhatsAppLog($logId, 'sent', $response, $metaMsgId);
            return ['success' => true, 'message' => 'WhatsApp message dispatched successfully via Meta API', 'meta_id' => $metaMsgId, 'log_id' => $logId];
        }

        $errMsg = $result['error']['message'] ?? $response;
        error_log("Meta WhatsApp Dispatch Error [ServiceNo: {$serviceNo}]: " . $errMsg);
        $this->updateWhatsAppLog($logId, 'failed', $errMsg);
        return ['success' => false, 'message' => 'Meta WhatsApp Error: ' . $errMsg];
    }

    private function logWhatsApp($phone, $recipientName, $message, $type, $serviceNo, $status) {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_phone VARCHAR(30),
                recipient_name VARCHAR(150),
                message TEXT,
                message_type VARCHAR(50),
                serviceNo VARCHAR(50),
                status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
                provider_response TEXT,
                meta_message_id VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt = $this->pdo->prepare("INSERT INTO whatsapp_logs (recipient_phone, recipient_name, message, message_type, serviceNo, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$phone, $recipientName, $message, $type, $serviceNo, $status]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function updateWhatsAppLog($logId, $status, $response, $metaMsgId = '') {
        if ($logId <= 0) return;
        try {
            $stmt = $this->pdo->prepare("UPDATE whatsapp_logs SET status = ?, provider_response = ?, meta_message_id = ? WHERE id = ?");
            $stmt->execute([$status, $response, $metaMsgId, $logId]);
        } catch (Exception $e) {}
    }

    // ============================================
    // HELPER FUNCTIONS
    // ============================================
    
    public function getOfficerPhone($serviceNo) {
        try {
            $stmt = $this->pdo->prepare("SELECT phone FROM tbl_emppersonal WHERE serviceNo = ?");
            $stmt->execute([$serviceNo]);
            $result = $stmt->fetch();
            return $result['phone'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function getOfficerEmail($serviceNo) {
        try {
            $stmt = $this->pdo->prepare("SELECT email FROM tbl_emppersonal WHERE serviceNo = ?");
            $stmt->execute([$serviceNo]);
            $result = $stmt->fetch();
            return $result['email'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
        public function validateNigerianPhone($phone) {
        $phone = $this->normalizeNigerianPhone($phone);
        // Accept any valid Nigerian format: 234XXXXXXXXXX (13 digits starting with 234)
        return preg_match('/^234[789]\d{9}$/', $phone);
    }
    
    public function normalizeNigerianPhone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Handle various Nigerian phone formats
        if (strlen($phone) === 10 && $phone[0] === '0') {
            // 080XXXXXXX -> 23480XXXXXXX
            return '234' . substr($phone, 1);
        }
        if (strlen($phone) === 11 && substr($phone, 0, 3) === '234') {
            // 23480XXXXXXX -> already correct
            return $phone;
        }
        if (strlen($phone) === 13 && substr($phone, 0, 3) === '234') {
            // 23480XXXXXXXX -> already correct
            return $phone;
        }
        if (strlen($phone) === 14 && $phone[0] === '+' && substr($phone, 1, 3) === '234') {
            // +23480XXXXXXXX -> 23480XXXXXXXX
            return substr($phone, 1);
        }
        if (strlen($phone) === 11 && $phone[0] === '0') {
            // 080XXXXXXXX -> 23480XXXXXXXX
            return '234' . substr($phone, 1);
        }
        
        // If we can't normalize, return the original cleaned number
        return $phone;
    }
    
    private function getEmailTemplate($type, $data) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'posting.niims.com.ng';
        $baseUrl = $protocol . $host;
        $bannerUrl = $baseUrl . '/assets/images/email_banner.png';

        $header = '
        <div style="width: 100%; overflow: hidden; background: #ffffff; text-align: center; border-bottom: 3px solid #1a5632;">
            <img src="' . $bannerUrl . '" alt="Nigeria Immigration Service" style="width: 100%; max-width: 650px; height: auto; display: block; margin: 0 auto;" />
        </div>
        <div style="background: linear-gradient(90deg, #1a5632 0%, #166534 60%, #0f392b 100%); padding: 12px 25px; text-align: right; color: #ffffff; font-family: \'Segoe UI\', Helvetica, Arial, sans-serif; font-size: 11px; letter-spacing: 1px; text-transform: uppercase; font-weight: 700; border-bottom: 2px solid #d4af37;">
            Personnel Posting Management System (NIS-PPMS)
        </div>
        <div style="padding: 30px 25px; font-family: \'Segoe UI\', Helvetica, Arial, sans-serif; color: #1e293b; background: #ffffff;">';
        
        $footer = '
        </div>
        <div style="background: #f8fafc; padding: 25px; text-align: center; font-family: \'Segoe UI\', Helvetica, Arial, sans-serif; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">
            <div style="max-width: 500px; margin: 0 auto; line-height: 1.6;">
                <p style="margin: 0 0 6px 0; font-weight: 700; color: #1a5632; font-size: 13px;">NIGERIA IMMIGRATION SERVICE</p>
                <p style="margin: 0 0 10px 0; font-size: 11px; color: #94a3b8;">Service Headquarters, Nnamdi Azikiwe Airport Road, Sauka, Abuja, Nigeria</p>
                <div style="border-top: 1px solid #e2e8f0; margin: 10px 0; padding-top: 10px; font-size: 11px; color: #94a3b8;">
                    This is an automated official notification generated by the NIS Personnel Posting System.<br>
                    Please do not reply directly to this email. For inquiries, contact your Zonal HQ or Service HQ.
                </div>
            </div>
        </div>';
        
        switch ($type) {
            case 'posting':
                $content = "
                <div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 5px solid #1a5632; padding: 16px 20px; border-radius: 6px; margin-bottom: 25px;'>
                    <div style='font-size: 11px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;'>Notice</div>
                    <div style='font-size: 18px; font-weight: 800; color: #1a5632; margin-top: 4px;'>OFFICIAL POSTING ORDER</div>
                </div>

                <p style='font-size: 15px; line-height: 1.6; margin-bottom: 20px;'>Dear <strong>{$data['officer_name']}</strong>,</p>
                <p style='font-size: 14px; line-height: 1.6; color: #334155; margin-bottom: 20px;'>
                    The Comptroller General of Immigration has approved your posting. Please find the details of your new posting assignment below:
                </p>

                <table style='width: 100%; border-collapse: collapse; margin: 25px 0; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;'>
                    <tr style='background: #f8fafc;'><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; width: 35%; color: #64748b; font-weight: 600;'>Service Number</td><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a;'>{$data['service_no']}</td></tr>
                    <tr><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600;'>Officer Full Name</td><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a;'>{$data['officer_name']}</td></tr>
                    <tr style='background: #f8fafc;'><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600;'>Substantive Rank</td><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a;'>{$data['officer_rank']}</td></tr>
                    <tr><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600;'>New Formation Posted To</td><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 800; font-size: 15px; color: #1a5632; background: #f0fdf4;'>{$data['new_location']}</td></tr>
                    <tr style='background: #f8fafc;'><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600;'>Posting Effective Date</td><td style='padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a;'>{$data['posting_date']}</td></tr>
                    <tr><td style='padding: 12px 16px; color: #64748b; font-weight: 600;'>Mandatory Reporting Deadline</td><td style='padding: 12px 16px; font-weight: 800; color: #dc2626; background: #fef2f2;'>{$data['report_deadline']}</td></tr>
                </table>

                <div style='background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 18px; border-radius: 6px; margin: 25px 0;'>
                    <div style='font-weight: 700; color: #b45309; font-size: 13px; margin-bottom: 4px;'>&#9888; STATUTORY REPORTING REQUIREMENT</div>
                    <div style='font-size: 13px; color: #78350f; line-height: 1.5;'>
                        You are required to report and submit yourself for documentation at your new formation (<strong>{$data['new_location']}</strong>) within 30 days of the effective date. Failure to report promptly will be logged as non-compliance and subject to statutory disciplinary procedure.
                    </div>
                </div>
                ";
                break;
                
            case 'reminder':
                $content = "
                <div style='background: #fef2f2; border: 1px solid #fecaca; border-left: 5px solid #dc2626; padding: 16px 20px; border-radius: 6px; margin-bottom: 25px;'>
                    <div style='font-size: 11px; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;'>High Priority Alert</div>
                    <div style='font-size: 18px; font-weight: 800; color: #dc2626; margin-top: 4px;'>POSTING REPORTING REMINDER</div>
                </div>

                <p style='font-size: 15px; line-height: 1.6; margin-bottom: 20px;'>Dear <strong>{$data['officer_name']}</strong>,</p>
                <p style='font-size: 14px; line-height: 1.6; color: #334155; margin-bottom: 20px;'>
                    Our central tracking records indicate that you were posted to <strong>{$data['new_location']}</strong> on <strong>{$data['posting_date']}</strong> (<strong>{$data['days_since']} days ago</strong>) and have not yet reported for admission.
                </p>

                <div style='background: #fef2f2; border: 1px solid #fee2e2; padding: 20px; border-radius: 8px; margin: 25px 0; text-align: center;'>
                    <div style='font-size: 16px; font-weight: 800; color: #dc2626; margin-bottom: 6px;'>🚨 IMMEDIATE ACTION REQUIRED</div>
                    <div style='font-size: 13px; color: #7f1d1d; line-height: 1.5;'>
                        Please report immediately to <strong>{$data['new_location']}</strong> for documentation or contact the posting authority at Service HQ without delay.
                    </div>
                </div>
                ";
                break;
                
            default:
                $content = "<p style='font-size: 14px; color: #334155;'>Notification from Nigeria Immigration Service Posting Management System.</p>";
        }
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Nigeria Immigration Service Notification</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f1f5f9; -webkit-font-smoothing: antialiased;">
            <div style="max-width: 650px; margin: 25px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid #e2e8f0;">
                ' . $header . $content . $footer . '
            </div>
        </body>
        </html>';
    }
    
    // ============================================
    // LOGGING FUNCTIONS
    // ============================================
    
    private function logSMS($phone, $name, $message, $type, $serviceNo, $status) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO sms_logs (recipient_phone, recipient_name, message, message_type, serviceNo, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$phone, $name, $message, $type, $serviceNo, $status]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function updateSMSLog($logId, $status, $response) {
        try {
            $stmt = $this->pdo->prepare("UPDATE sms_logs SET status = ?, provider_response = ?, sent_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $response, $logId]);
        } catch (Exception $e) {}
    }
    
    private function logEmail($email, $name, $subject, $body, $type, $serviceNo, $attachment, $status) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, body, email_type, serviceNo, attachment_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$email, $name, $subject, $body, $type, $serviceNo, $attachment, $status]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function updateEmailLog($logId, $status, $response) {
        try {
            $stmt = $this->pdo->prepare("UPDATE email_logs SET status = ?, provider_response = ?, sent_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $response, $logId]);
        } catch (Exception $e) {}
    }
    
    // ============================================
    // AUTO-REMINDER CHECKER
    // ============================================
    
    /**
     * Check for officers who haven't reported and send reminders
     */
    public function processAutoReminders() {
        try {
            // Get all pending notifications older than configured days
            $reminderDays = 14;
            $settings = $this->pdo->query("SELECT sms_reminder_days FROM notification_settings LIMIT 1")->fetch();
            if ($settings && $settings['sms_reminder_days']) {
                $reminderDays = intval($settings['sms_reminder_days']);
            }
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM posting_notifications 
                WHERE status = 'pending' 
                AND created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND reminder_sent = 0
            ");
            $stmt->execute([$reminderDays]);
            $pendingNotifications = $stmt->fetchAll();
            
            $remindersSent = 0;
            
            foreach ($pendingNotifications as $notif) {
                $daysSincePosting = floor((time() - strtotime($notif['created_at'])) / 86400);
                
                // Send SMS reminder
                $smsResult = $this->sendReminderSMS(
                    $notif['serviceNo'],
                    $notif['officer_name'],
                    $notif['posting_location'],
                    $notif['posting_date'],
                    $daysSincePosting
                );
                
                // Send Email reminder
                $emailResult = $this->sendReminderEmail(
                    $notif['serviceNo'],
                    $notif['officer_name'],
                    $notif['posting_location'],
                    $notif['posting_date'],
                    $daysSincePosting
                );
                
                // Mark reminder as sent
                $this->pdo->prepare("UPDATE posting_notifications SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?")
                    ->execute([$notif['id']]);
                
                $remindersSent++;
                
                // Prevent too many SMS in one batch (rate limiting)
                if ($remindersSent >= 50) break;
            }
            
            return ['success' => true, 'reminders_sent' => $remindersSent];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}