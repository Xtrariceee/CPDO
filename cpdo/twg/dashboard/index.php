<?php
require_once __DIR__ . '/../../../app/bootstrap_cpdo.php';
require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
redirect('twg/index.php');
