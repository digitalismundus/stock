<?php
// The shell is public; all portfolio and security API endpoints require a session.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
readfile(__DIR__ . '/index.html');
