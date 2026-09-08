<?php
/**
 * NIS-PPMS Google Authenticator TOTP Helper
 * RFC 6238 Compliant Time-based One-Time Password Implementation
 */

if (!class_exists('GoogleAuthenticator')) {
    class GoogleAuthenticator {
        private static $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        /**
         * Create a new 16-character Base32 secret string
         */
        public static function createSecret($secretLength = 16) {
            $secret = '';
            $rnd = random_bytes($secretLength);
            for ($i = 0; $i < $secretLength; $i++) {
                $secret .= self::$base32chars[ord($rnd[$i]) & 31];
            }
            return $secret;
        }

        /**
         * Calculate 6-digit TOTP code for a given timestamp
         */
        public static function getCode($secret, $timeSlice = null) {
            if ($timeSlice === null) {
                $timeSlice = floor(time() / 30);
            }

            $secretkey = self::base32Decode($secret);
            if ($secretkey === false) return false;

            // Pack time into 8-byte big-endian binary string
            $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);

            // HMAC-SHA1 calculation
            $hmac = hash_hmac('SHA1', $time, $secretkey, true);
            $offset = ord(substr($hmac, -1)) & 0x0F;
            $hashpart = substr($hmac, $offset, 4);

            $value = unpack('N', $hashpart);
            $value = $value[1] & 0x7FFFFFFF;

            $modulo = pow(10, 6);
            return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
        }

        /**
         * Verify code with 1-slice time drift tolerance (±30 seconds)
         */
        public static function verifyCode($secret, $code, $discrepancy = 1) {
            $currentTimeSlice = floor(time() / 30);
            $code = str_replace(' ', '', trim($code));

            if (strlen($code) !== 6 || !ctype_digit($code)) {
                return false;
            }

            for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
                $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
                if ($calculatedCode !== false && hash_equals($calculatedCode, $code)) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Base32 Decode implementation
         */
        private static function base32Decode($secret) {
            if (empty($secret)) return '';
            $base32chars = self::$base32chars;
            $base32charsFlipped = array_flip(str_split($base32chars));

            $secret = strtoupper($secret);
            $paddingCharCount = substr_count($secret, '=');
            $allowedValues = array(6, 4, 3, 1, 0);
            if (!in_array($paddingCharCount, $allowedValues)) return false;

            for ($i = 0; $i < 4; ++$i) {
                if ($paddingCharCount == $allowedValues[$i] &&
                    substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) return false;
            }
            $secret = str_replace('=', '', $secret);
            $secret = str_split($secret);
            $binaryString = '';
            for ($i = 0; $i < count($secret); $i = $i + 8) {
                $x = '';
                if (!in_array($secret[$i], array_keys($base32charsFlipped))) return false;
                for ($j = 0; $j < 8; ++$j) {
                    if (!isset($secret[$i + $j])) break;
                    $x .= str_pad(base_convert($base32charsFlipped[$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
                }
                $eightBits = str_split($x, 8);
                for ($z = 0; $z < count($eightBits); ++$z) {
                    $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
                }
            }
            return $binaryString;
        }

        /**
         * Generate QR Code Image URL using high-reliability QuickChart API
         */
        public static function getQrUrl($name, $secret, $title = 'NIS-PPMS') {
            $otpUrl = "otpauth://totp/" . rawurlencode($title) . ":" . rawurlencode($name) . "?secret=" . $secret . "&issuer=" . rawurlencode($title);
            return "https://quickchart.io/qr?text=" . urlencode($otpUrl) . "&size=180&margin=1";
        }

        /**
         * Generate Direct Mobile Deep-Link OTPAuth URL
         */
        public static function getOtpAuthUrl($name, $secret, $title = 'NIS-PPMS') {
            return "otpauth://totp/" . rawurlencode($title) . ":" . rawurlencode($name) . "?secret=" . $secret . "&issuer=" . rawurlencode($title);
        }
    }
}
