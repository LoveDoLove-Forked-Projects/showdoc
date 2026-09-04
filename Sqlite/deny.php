<?php
// SQLite 目录访问守卫：任何 PHP 文件（含数据库 .db.php）执行前先被本文件拦截
http_response_code(403);
exit('Forbidden');
