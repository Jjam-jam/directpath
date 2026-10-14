<?php
$server = "";
$user = "";
$password = "";
$dbname = "";

$conn = new mysqli($server, $user, $password, $dbname);

if (!$conn) {
    echo "Error!: {$conn->connect_error}";
}

?>