<?php
use App\Services\Csrf;

$csrf = Csrf::token();
if (!$selectedEvent) {
    return;
}
?>
<form method="post" action="/admin/agm/actions.php" class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_content">
    <input type="hidden" name="event_id" value="<?= (int) $selectedEvent['id'] ?>">

    <div>
        <h2 class="font-display text-lg font-semibold text-gray-900">Public landing page content</h2>
        <p class="text-sm text-gray-500 mt-1">This rich-text content appears on the public AGM page at <a href="/agm/" class="text-primary hover:underline">/agm/</a> when this event is published as the current AGM.</p>
    </div>

    <label class="block">
        <span class="text-sm font-medium text-gray-700">Cover image path</span>
        <span class="block text-xs text-gray-500 mb-1">Optional URL or path under /uploads/ that's shown as a hero image above the description.</span>
        <input type="text" name="cover_image_path" value="<?= e($selectedEvent['cover_image_path'] ?? '') ?>" placeholder="/uploads/agm/perth-2026-hero.jpg" class="mt-1 block w-full rounded-lg border-gray-300">
    </label>

    <div>
        <span class="text-sm font-medium text-gray-700 block mb-1">Description (rich text)</span>
        <span class="block text-xs text-gray-500 mb-2">Use the formatting toolbar to add headings, lists, links, and images. Saves as HTML.</span>
        <textarea id="agm-description-editor" name="description_html" rows="20" class="block w-full rounded-lg border-gray-300"><?= e($selectedEvent['description_html'] ?? '') ?></textarea>
    </div>

    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
        <button class="rounded-lg bg-primary text-white px-4 py-2 text-sm font-semibold">Save content</button>
    </div>
</form>

<script src="/assets/vendor/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function() {
    function fallbackTextarea() {
        var ta = document.getElementById('agm-description-editor');
        if (!ta) return;
        ta.style.minHeight = '480px';
        ta.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, monospace';
    }
    if (typeof tinymce === 'undefined') {
        fallbackTextarea();
        var warn = document.createElement('div');
        warn.className = 'mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800';
        warn.textContent = 'TinyMCE bundle not found at /assets/vendor/tinymce/. Editing falls back to plain HTML. Run scripts/install_tinymce.sh to enable the WYSIWYG editor.';
        var ta = document.getElementById('agm-description-editor');
        if (ta && ta.parentNode) ta.parentNode.appendChild(warn);
        return;
    }
    tinymce.init({
        selector: '#agm-description-editor',
        height: 500,
        menubar: false,
        license_key: 'gpl',
        plugins: 'lists link image table code autolink',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image table | alignleft aligncenter alignright | code',
        content_style: 'body{font-family:system-ui,sans-serif;font-size:15px}',
        relative_urls: false,
        remove_script_host: false,
        document_base_url: window.location.origin + '/'
    });
})();
</script>
