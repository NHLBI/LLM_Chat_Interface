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
    if (empty($modelconfig['enabled'])) continue;
    $label = $modelconfig['label'];
    $tooltip = $modelconfig['tooltip'];
    $checked = ($m == $_SESSION['deployment']) ? 'true' : 'false';
    $handles_images = !empty($modelconfig['handles_images']) ? 'true' : 'false';
    echo '
        <button type="button" 
                class="model-option" 
                data-model="'.$m.'"
                data-handles-images="'.$handles_images.'"
                role="radio"
                aria-checked="'.$checked.'">
            <h5>'.$label.'</h5>
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

.model-option p {
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

function updateModelButtonStates() {
    const modelOptions = document.querySelectorAll('.model-option');
    modelOptions.forEach(option => {
        const canHandleImages = option.dataset.handlesImages === 'true';
        if (window.documentsLength > 0 && !canHandleImages) {
            option.classList.add('disabled-model');
            option.setAttribute('aria-disabled', 'true');
        } else {
            option.classList.remove('disabled-model');
            option.setAttribute('aria-disabled', 'false');
        }
    });
}

// (unchanged from your example)
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('model_select');
    const modelOptions = document.querySelectorAll('.model-option');
    const hiddenInput = document.getElementById('selected_model');

    // Disable models that can't handle images if documents are uploaded
    modelOptions.forEach(option => {
        const canHandleImages = option.dataset.handlesImages === 'true';
        if (documentsLength > 0 && !canHandleImages) {
            option.classList.add('disabled-model');
            option.setAttribute('aria-disabled', 'true');
        } else {
            option.setAttribute('aria-disabled', 'false');
        }
    });

    // Set initial state based on current model
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

