<?php
require 'includes/bootstrap.php';
$sql = file_get_contents('database/migrations/034_inventory_purchase_orders.sql');
mysqli_multi_query($connection, $sql);
while (mysqli_next_result($connection)) {;}
echo mysqli_error($connection) ?: "Migration complete.";
