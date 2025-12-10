<div class="aboutModelsWindow" role="dialog" aria-modal="true" aria-labelledby="modelModalTitle">
  <!-- The “modal content” container -->
  <div class="modelsModalContent">
    
    <!-- Close button -->
    <button class="closeAbout" onclick="closeAboutModels()" aria-label="Close Model Selection">&times;</button>

    
    <h4 id="modelModalTitle">Select a Model</h4>
    
    <form id="model_select" action="" method="post">
        <input type="hidden" name="model" id="selected_model" value="">
        <div class="model-options">

        <?php

foreach ($models as $m => $modelconfig) {

    #echo "<pre>".print_r($models,1)."</pre>";


    if (empty($modelconfig['enabled'])) continue;
    $label = $modelconfig['label'];
    $tooltip = $modelconfig['tooltip'];
    $checked = ($m == $_SESSION['deployment']) ? 'true' : 'false';
    $handles_images = !empty($modelconfig['handles_images']) ? 'true' : 'false';
    $handles_documents = !empty($modelconfig['handles_documents']) ? 'true' : 'false';
    if ($handles_documents == 'false') {
        $disabled_note = '<span id="" class="model-select-note" style="font-weight:normal;"></span>';

    } elseif ($handles_images == 'false') {
        $disabled_note = '<span id="" class="model-select-note" style="font-weight:normal;"></span>';
    } else {
        $disabled_note = '';
    }
    echo '
        <button type="button" 
                class="model-option" 
                data-model="'.$m.'"
                data-handles-images="'.$handles_images.'"
                data-handles-documents="'.$handles_documents.'"
                role="radio"
                aria-checked="'.$checked.'">
            <h5>'.$label.' '.$disabled_note.'</h5>
            <p>'.$tooltip.'</p>
        </button>
    '."\n";
}

        ?>

        </div>
    </form>
  </div><!-- .modelsModalContent -->
</div><!-- .aboutModelsWindow -->

<!-- Keep your existing styles for .model-options, .model-option, etc. -->
<style>
.model-options {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1.5rem;
}

.model-option {
    display: block;
    width: 100%;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: all 0.2s ease;
}

.model-option:hover {
    border-color: #93c5fd;
    background-color: #f8fafc;
}

.model-option:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

.model-option[aria-checked="true"] {
    border-color: #3b82f6;
    background-color: #eff6ff;
}

.model-option h5 {
    margin: 0 0 0.5rem 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.model-option ul {
    margin-bottom: 0px;
  display: flex;
  gap: 1.5em;
  list-style: disc inside;
  padding: 0;
  margin: 0;
}
.model-option p, .model-option li {
    margin: 0;
    font-size: 0.9rem;
    color: #475569;
}
.disabled-model {
    opacity: 0.5;
    pointer-events: none;
    cursor: not-allowed;
}

</style>

<script>
/**
 * Fetch all (non‑deleted) documents and split into image vs other counts.
 */
function loadDocumentCounts() {
    if (!chatId) {
        console.warn('No chatId available; skipping document count fetch');
        window.imageDocumentsCount = 0;
        window.documentsLength    = 0;
        updateModelButtonStates();
        return;
    }

    var basePath = '';
    if (typeof application_path === 'string' && application_path.length) {
        basePath = '/' + application_path.replace(/^\/+|\/+$/g, '');
    }
    var url = `${basePath}/get_uploaded_images.php?chat_id=${encodeURIComponent(chatId)}&images_only=false`;
    console.info('[ModelModal] fetching documents', { url: url, chatId: chatId, basePath: basePath });

    fetch(url)
        .then(res => {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        })
        .then(docs => {
            if (!Array.isArray(docs)) {
                throw new Error('Unexpected payload');
            }
            window.imageDocumentsCount = docs.filter(doc => (doc.document_type || '').includes('image')).length;
            window.documentsLength    = docs.length - window.imageDocumentsCount;
            updateModelButtonStates();
            console.info('[ModelModal] document counts', {
                images: window.imageDocumentsCount,
                other: window.documentsLength,
                total: docs.length
            });
        })
        .catch(err => {
            console.error('[ModelModal] Error fetching documents:', err);
            window.imageDocumentsCount = 0;
            window.documentsLength    = 0;
            updateModelButtonStates();
        });
}

function updateModelButtonStates() {
    document.querySelectorAll('.model-option').forEach(option => {
        const canHandleImages    = option.dataset.handlesImages    === 'true';
        const canHandleDocuments = option.dataset.handlesDocuments === 'true';

        const hasImages = window.imageDocumentsCount > 0;
        const hasDocs   = window.documentsLength    > 0;

        const disable = (hasImages && !canHandleImages) || (hasDocs && !canHandleDocuments);
        option.classList.toggle('disabled-model', disable);
        option.setAttribute('aria-disabled', disable.toString());

        const note = option.querySelector('.model-select-note');
        if (!note) return;

        if (disable) {
            // PRIORITIZE document‑incompatibility over image‑incompatibility
            if (hasDocs && !canHandleDocuments) {
                note.textContent = '(This model cannot be selected if you have uploaded documents)';
            } else if (hasImages && !canHandleImages) {
                note.textContent = '(This model cannot be selected if you have uploaded images)';
            }
            note.style.display = 'inline';
        } else {
            note.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('model_select');
    const modelOptions = document.querySelectorAll('.model-option');
    const hiddenInput = document.getElementById('selected_model');
    
    // Use the image count from our AJAX call to disable models that cannot handle images.
    modelOptions.forEach(option => {
        const canHandleImages = option.dataset.handlesImages === 'true';
        if (window.imageDocumentsCount > 0 && !canHandleImages) {
            option.classList.add('disabled-model');
            option.setAttribute('aria-disabled', 'true');
        } else {
            option.setAttribute('aria-disabled', 'false');
        }
    });
    
    // Set initial state based on the current model.
    const currentModel = hiddenInput.value || '';
    document.querySelector(`[data-model="${currentModel}"]`)?.setAttribute('aria-checked', 'true');
    
    modelOptions.forEach(option => {
        option.addEventListener('click', () => {
            if (option.classList.contains('disabled-model')) return;
            selectModel(option);
        });
    
        option.addEventListener('keydown', (e) => {
            if ((e.key === 'Enter' || e.key === ' ') && !option.classList.contains('disabled-model')) {
                e.preventDefault();
                selectModel(option);
            }
        });
    });
    
    function selectModel(selectedOption) {
        modelOptions.forEach(opt => opt.setAttribute('aria-checked', 'false'));
        selectedOption.setAttribute('aria-checked', 'true');
        hiddenInput.value = selectedOption.dataset.model;
        form.submit();
    }
});

</script>
