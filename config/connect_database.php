<?php
    $mysql_connection = mysqli_connect("localhost", "root", "", "Kursach_Shein");
    if (!$mysql_connection) { die('Ошибка подключения: ' . mysqli_connect_error()); }
    mysqli_set_charset($mysql_connection, "utf8mb4");
?>