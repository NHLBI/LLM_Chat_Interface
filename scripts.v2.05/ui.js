// ui.js
function scrollToBottom() {
    const messageList = document.getElementById('messageList');
    messageList.scrollTop = messageList.scrollHeight;
}
function showIcons(chatItem) {
    $(chatItem).find('.chat-icon').css('visibility', 'visible');
}
function hideIcons(chatItem) {
    $(chatItem).find('.chat-icon').css('visibility', 'hidden');
}

function showAboutUs() {
  const disclaimer = document.querySelector('.disclaimerWindow');
  disclaimer.classList.add('show');
  const closeBtn = disclaimer.querySelector('.closeDisclaimer');
  if (closeBtn) closeBtn.focus();
}
function closeAboutUs() {
  const disclaimer = document.querySelector('.disclaimerWindow');
  disclaimer.classList.remove('show');
  // Return focus if you wish:
  const userMessage = document.getElementById('userMessage');
  if (userMessage) {
    userMessage.focus();
  }
}
function showAboutModels() {
    const aboutWindow = document.querySelector('.aboutModelsWindow');
    aboutWindow.style.display = 'flex';
    aboutWindow.classList.add('show');

    loadDocumentCounts();    // <<<<< call your existing helper here

    document.querySelector('.closeButton').focus();
}
function showCannedModal() {
    const modal = document.querySelector('.cannedModalWindow');
    if (modal) {
        console.log("testing found modal");
        modal.classList.add('show');
        modal.style.display = 'flex';
        modal.querySelector('.closeCanned')?.focus();
    }
}

/**
 * Only hides the canned‑workflow modal.
 */
function closeCannedModal() {
  const modal = document.querySelector('.cannedModalWindow');
  if (!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
}

/**
 * Hides the canned modal *and* hard‑deletes the just‑created chat.
 * Bound only to the “×” cancel button.
 */
function cancelCannedModal() {
  // 1) Close the modal
  closeCannedModal();

  // 2) Figure out the chatId from the URL
  const path   = window.location.pathname.replace(/\/$/, '');
  const chatId = path.substring(path.lastIndexOf('/') + 1);

  // 3) Hard‑delete silently
  deleteChat(chatId, '', {
    silent: true,
    hard:   true
  });
}

function closeAboutModels() {
    console.log("Close window clicked");
    var aboutWindow = document.querySelector('.aboutModelsWindow');
    aboutWindow.classList.remove('show');  // Remove the 'show' class
    aboutWindow.style.display = 'none';      // Hide the modal by setting display to 'none'
    var userMessage = document.getElementById('userMessage');
    if (userMessage) {
        userMessage.focus();  // Return focus to the message input if it exists
    }
}

document.addEventListener('DOMContentLoaded', function() {
  const workflowButtons = document.querySelectorAll('.canned-option');
  workflowButtons.forEach(button => {
    button.addEventListener('click', function() {
      // Get the raw attribute values.
      const workflowId = button.getAttribute('data-workflow-id');
      const configLabelRaw = button.getAttribute('data-config-label');
      const configDescriptionRaw = button.getAttribute('data-config-description');
      const prompt = button.dataset.prompt;
      const action = button.dataset.action;
      
      // Parse the comma-separated values into an object.
      let workflowConfig = {};
      if (configLabelRaw && configDescriptionRaw) {
        const labelsArray = configLabelRaw.split(',');
        const descArray = configDescriptionRaw.split(',');
        for (let i = 0; i < labelsArray.length; i++) {
          let key = labelsArray[i].trim();
          let value = descArray[i] ? descArray[i].trim() : "";
          workflowConfig[key] = value;
        }
      }
      
      // Save the workflow info in a global variable.
      window.selectedWorkflow = {
        workflowId: workflowId,
        configLabel: configLabelRaw,           // raw string if needed elsewhere
        configDescription: configDescriptionRaw, // raw string if needed elsewhere
        config: workflowConfig,                // the parsed object
        prompt: prompt,
        action: action
      };
      console.log("Selected workflow:", window.selectedWorkflow);

      // **** New lines Start ****
      // Update the exchange_type hidden input to "workflow"
      $('#exchange_type').val('workflow');
      console.log("Updated exchange_type to 'workflow'");
      // Update custom_config hidden input with the selected workflow's JSON
      $('#custom_config').val(JSON.stringify(window.selectedWorkflow));
      console.log("Updated custom_config with selectedWorkflow");
      // **** New lines End ****

      // Close the canned modal window and open the upload modal window.
      closeCannedModal();
      openUploadModal();
    });
  });
});

// When opening the upload modal, update maxUploads based on selected workflow
// When opening the upload modal, update maxUploads and UI based on selected workflow
function openUploadModal() {
  // First, decide on maxUploads based on workflow configuration
  if (window.selectedWorkflow && window.selectedWorkflow.config) {
    // If the workflow config object contains a key "single-text-fileupload",
    // then we assume that means only one file is allowed.
    if (window.selectedWorkflow.config["single-text-fileupload"]) {
      maxUploads = 1;
    } else {
      maxUploads = defaultMaxUploads;
    }
    
    // Update the modal title if a custom title is provided.
    if (window.selectedWorkflow.config["modal-upload-title"]) {
      document.getElementById('uploadModalTitle').textContent = window.selectedWorkflow.config["modal-upload-title"];
    } else {
      document.getElementById('uploadModalTitle').textContent = "Upload Document(s)";
    }
    
    // Update the instruction message if available.
    if (window.selectedWorkflow.config["modal-upload-instruction"]) {
      document.getElementById('uploadModalMessage').textContent = window.selectedWorkflow.config["modal-upload-instruction"];
    } else {
      const remaining = maxUploads - (window.documentsLength || 0);
      document.getElementById('uploadModalMessage').textContent = remaining === 1 
        ? 'You can upload 1 more document.' 
        : `You can upload up to ${remaining} file${remaining > 1 ? 's' : ''}.`;
    }
    
    // Optionally, update the upload button text.
    if (window.selectedWorkflow.config["modal-upload-button"]) {
      document.querySelector('button[onclick="submitUploadForm()"]').textContent = window.selectedWorkflow.config["modal-upload-button"];
    }
  } else {
    // No selected workflow or configuration is available, so use defaults.
    maxUploads = defaultMaxUploads;
    document.getElementById('uploadModalTitle').textContent = "Upload Document(s)";
    const remaining = maxUploads - (window.documentsLength || 0);
    document.getElementById('uploadModalMessage').textContent = remaining === 1 
      ? 'You can upload 1 more document.' 
      : `You can upload up to ${remaining} file${remaining > 1 ? 's' : ''}.`;
  }

  const currentDocs = window.documentsLength || 0;
  console.log(`Documents Length: ${currentDocs}, Maximum allowed: ${maxUploads}`);

  // If the user already has maxUploads documents, do not let them upload more.
  if (currentDocs >= maxUploads) {
    alert(`You have ${currentDocs} document(s) uploaded. You cannot add more.`);
    return;
  }

  const modal = document.getElementById('uploadModal');
  modal.style.display = 'flex';
  // Reset any previous selections.
  uploadedFiles = [];
  updatePreview();
}


function adjustChatTitlesHeight() {
    // Select the chat-titles-container and the menu bottom content
    const chatTitlesContainer = document.querySelector('.chat-titles-container');
    const menuBottomContent = document.querySelector('.mt-auto.p-2');

    // Calculate available height
    const viewportHeight = window.innerHeight - 130;
    const menuBottomHeight = menuBottomContent.offsetHeight + 60;

    //console.log(viewportHeight)
    //console.log(menuBottomHeight)

    // Adjust for any padding/margin as needed
    const paddingOffset = 0; // Adjust this as necessary

    // Set the height dynamically with a minimum of 200px
    const newHeight = Math.max(viewportHeight - menuBottomHeight - paddingOffset, 360);
    chatTitlesContainer.style.height = `${newHeight}px`;
}
function updatePlaceholder() {
  const userMessageInput = document.getElementById('userMessage');

  // Priority: dall-e > Document > Default
  if (deployment === 'azure-dall-e-3') {
    userMessageInput.placeholder = "Type a detailed description of your image to create... (Note: does not use previous prompts or images.)";
  } else if (document_name) {
    userMessageInput.placeholder = "Type your questions about your uploaded file... (Note: does not use previous prompts or replies.)";
  } else {
    userMessageInput.placeholder = "Type your message...";
  }
}

