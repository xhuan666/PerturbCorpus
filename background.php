<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
if ($currentPage !== 'index.php') {
	return;
}
?>
<div id="cellBg" style="position: fixed; inset: 0; z-index: 0; pointer-events: none; background-image: url('static/cell.png'); background-size: cover; background-position: center center; background-repeat: no-repeat;">
</div>





