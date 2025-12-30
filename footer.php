<?php

?>
<footer class="portal-footer mt-4">
    <div class="container py-3 text-center small">
        Powered by <a href="https://github.com/JSY-Ben/ExamSubs" target="_blank" rel="noopener">ExamSubs</a> · Created by <a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener">Ben Pirozzolo</a>
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
