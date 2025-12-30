<?php
$version_path = __DIR__ . '/version.txt';
$version_label = '';
if (is_readable($version_path)) {
    $version_value = trim((string) file_get_contents($version_path));
    if ($version_value !== '') {
        $version_label = 'Version ' . $version_value;
    }
}
?>
<footer class="portal-footer mt-4">
    <div class="container py-3 text-center small">
        Powered by <a href="https://github.com/JSY-Ben/ExamSubs" target="_blank" rel="noopener">ExamSubs</a><?php if ($version_label !== '') { ?> · <?php echo htmlspecialchars($version_label, ENT_QUOTES, 'UTF-8'); ?><?php } ?> · Created by <a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener">Ben Pirozzolo</a>
    </div>
</footer>
<script>
    document.addEventListener('focusin', (event) => {
        const target = event.target;
        if (!target || (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA')) {
            return;
        }
        if (target.hasAttribute('placeholder')) {
            if (!target.dataset.placeholderBackup) {
                target.dataset.placeholderBackup = target.getAttribute('placeholder') || '';
            }
            target.setAttribute('placeholder', '');
        }
    });

    document.addEventListener('focusout', (event) => {
        const target = event.target;
        if (!target || (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA')) {
            return;
        }
        if (target.dataset.placeholderBackup && target.value.trim() === '') {
            target.setAttribute('placeholder', target.dataset.placeholderBackup);
        }
    });
</script>
</body>
</html>
