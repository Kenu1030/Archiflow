<?php
namespace Archiflow\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configPath = __DIR__ . '/../config/mail.php';
            if (!file_exists($configPath)) {
                throw new \RuntimeException('Mail config not found.');
            }
            $config = require $configPath;
        }
        $this->config = $config;
    }

    public function send(array $opts): array
    {
        $defaults = [
            'to_email' => null,
            'to_name' => null,
            'subject' => '',
            'html' => '',
            'text' => '',
            'attachments' => [], // each: ['data' => string, 'name' => string, 'type' => mime]
        ];
        $data = array_merge($defaults, $opts);

        if (!$data['to_email'] || !filter_var($data['to_email'], FILTER_VALIDATE_EMAIL)) {
            return [false, 'Invalid recipient email'];
        }
        if ($data['subject'] === '' || $data['html'] === '' && $data['text'] === '') {
            return [false, 'Subject and body required'];
        }

        $mail = new PHPMailer(true);
        try {
            if (($this->config['transport'] ?? 'smtp') === 'smtp') {
                $mail->isSMTP();
                $mail->Host = $this->config['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $this->config['username'];
                $mail->Password = $this->config['password'];
                $mail->Port = $this->config['port'];
                $mail->SMTPSecure = $this->config['encryption'];
            }
            $mail->CharSet = 'UTF-8';
            if (!empty($this->config['debug'])) { $mail->SMTPDebug = (int)$this->config['debug']; }

            $fromEmail = $this->config['from_email'];
            $fromName  = $this->config['from_name'] ?? 'Archiflow';
            $mail->setFrom($fromEmail, $fromName);
            $replyToEmail = $this->config['reply_to_email'] ?? $fromEmail;
            $replyToName  = $this->config['reply_to_name'] ?? $fromName;
            $mail->addReplyTo($replyToEmail, $replyToName);
            $mail->addAddress($data['to_email'], $data['to_name'] ?: '');
            $mail->Subject = $data['subject'];

            if ($data['html']) {
                $mail->isHTML(true);
                $mail->Body = $data['html'];
                $mail->AltBody = $data['text'] ?: strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $data['html']));
            } else {
                $mail->Body = $data['text'];
                $mail->AltBody = $data['text'];
            }

            foreach ($data['attachments'] as $att) {
                if (!isset($att['data'], $att['name'])) continue;
                $mail->addStringAttachment($att['data'], $att['name'], 'base64', $att['type'] ?? 'application/octet-stream');
            }

            $mail->send();
            return [true, null];
        } catch (Exception $e) {
            return [false, $e->getMessage()];
        }
    }
}

// Convenience function
function send_mail(array $opts): array {
    static $mailer = null;
    if ($mailer === null) { $mailer = new Mailer(); }
    return $mailer->send($opts);
}
