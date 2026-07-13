<?php

require_once "PHPMailer/src/Exception.php";
require_once "PHPMailer/src/PHPMailer.php";
require_once "PHPMailer/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an account verification email.
 *
 * @param string $email Recipient's email address.
 * @param string $name Recipient's name.
 * @param string $token Verification token.
 * @return bool True on success, false on failure.
 */
function sendVerificationEmail($email, $name, $token)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;

        // CHANGE THESE
        $mail->Username = "worldexplorerapp00@gmail.com";
        $mail->Password = "jupl uwxg kqim mpks";

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Sender
        $mail->setFrom("worldexplorerapp00@gmail.com", "World Explorer");

        // Recipient
        $mail->addAddress($email, $name);

        // Email
        $mail->isHTML(true);
        $mail->Subject = "Verify your World Explorer account";

        // Change localhost if your project is hosted online
        $verificationLink = "http://localhost/world-explorer-app/verify.php?token=" . urlencode($token);

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;padding:20px'>
            <h2>Welcome to World Explorer 🌍</h2>

            <p>Hello <strong>{$name}</strong>,</p>

            <p>Thank you for creating your World Explorer account.</p>

            <p>Please verify your email address by clicking the button below.</p>

            <p style='margin:30px 0'>
                <a href='{$verificationLink}'
                   style='background:#0f766e;
                          color:white;
                          padding:12px 24px;
                          text-decoration:none;
                          border-radius:8px;
                          display:inline-block;'>
                    Verify Email
                </a>
            </p>

            <p>If the button doesn't work, copy and paste this link into your browser:</p>

            <p>{$verificationLink}</p>

            <hr>

            <p>This link will remain valid until you verify your account.</p>

            <p>World Explorer Team</p>
        </div>";

        $mail->AltBody =
            "Welcome to World Explorer!\n\n"
            ."Verify your account by visiting:\n\n"
            .$verificationLink;

        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log("Mailer Error: " . $mail->ErrorInfo);

        return false;
    }
}