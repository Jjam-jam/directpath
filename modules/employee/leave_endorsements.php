<?php
require_once '../../includes/auth.php';
requireTeamLeader();
header('Location: team_leave.php?status=awaiting', true, 302);
exit;
