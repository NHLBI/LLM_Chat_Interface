<?php
declare(strict_types=1);
?>
<div class="imagePreviewWindow" role="dialog" aria-modal="true" aria-labelledby="imagePreviewTitle">
  <div class="imagePreviewModalContent">
    <button type="button"
            class="closeButton closeImagePreview"
            onclick="closeImagePreview()"
            aria-label="Close image preview">&times;</button>
    <h4 id="imagePreviewTitle">Image preview</h4>
    <div class="image-preview-body">
      <img id="imagePreviewElement" class="image-preview-img" alt="" />
    </div>
    <div id="imagePreviewCaption" class="image-preview-caption" role="note"></div>
  </div>
</div>
