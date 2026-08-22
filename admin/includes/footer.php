<?php
// admin/includes/footer.php

// Dynamically calculate the path to the assets folder
$currentFolder = basename(dirname($_SERVER['PHP_SELF']));
$assetPath = ($currentFolder === 'invoice') ? '../../assets/' : '../assets/';
?>

<!-- Load custom JavaScript (You can create this file later for global functions) -->
<script src="<?= $assetPath ?>js/app.js"></script>
<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay');
document.querySelectorAll('[data-sidebar-toggle]').forEach(b=>b.addEventListener('click',()=>{sidebar?.classList.toggle('open');overlay?.classList.toggle('hidden')}));
document.getElementById('closeSidebar')?.addEventListener('click',()=>{sidebar?.classList.remove('open');overlay?.classList.add('hidden')});
overlay?.addEventListener('click',()=>{sidebar?.classList.remove('open');overlay.classList.add('hidden')});
</script>

</body>

</html>
