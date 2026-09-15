<?php

class Logger
{
    private $db;
    private $errorLogFile;

    public function __construct($dbConnection)
    {
        $this->db = $dbConnection;
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->errorLogFile = $logDir . '/error_' . date('Y-m-d') . '.log';
    }
    public function logAction($userId, $userRole, $action, $entityType = null, $entityId = null, $details = null)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sql = "INSERT INTO action_logs (user_id, user_role, action, entity_type, entity_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($this->db, $sql);
        mysqli_stmt_bind_param($stmt, 'isssisss', $userId, $userRole, $action, $entityType, $entityId, $details, $ip, $ua);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    public function logError($level, $message, $file = null, $line = null, $trace = null, $userId = null)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $sql = "INSERT INTO error_logs (error_level, message, file, line, trace, user_id, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($this->db, $sql);
        mysqli_stmt_bind_param($stmt, 'sssssis', $level, $message, $file, $line, $trace, $userId, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $logLine = date('Y-m-d H:i:s') . " [$level] $message";
        if ($file) $logLine .= " in $file:$line";
        if ($userId) $logLine .= " (user_id=$userId)";
        $logLine .= PHP_EOL;
        file_put_contents($this->errorLogFile, $logLine, FILE_APPEND);
    }
}