<?php
require_once __DIR__ . '/../connection.php';

respondJson([
    'status' => 'failed',
    'message' => 'Delete action is not supported for the PokeAPI wrapper.',
], 405);
