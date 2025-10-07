<style>
/* Modal overlay styling */
.uploadModal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

/* Modal content styling */
.modalContent {
  background: #fff;
  padding: 20px;
  border-radius: 1px;
  width: 90%;
  max-width: 500px;
  position: relative;
}

/* Close button styling */
.closeButton {
  position: absolute;
  top: 10px;
  right: 10px;
  font-size: 20px;
  border: none;
  background: none;
  cursor: pointer;
}

/* Drop zone styling */
.dropZone {
  border: 2px dashed #ccc;
  padding: 20px;
  text-align: center;
  cursor: pointer;
  margin-bottom: 10px;
  transition: background-color 0.2s;
}

.dropZone:hover,
.dropZone:focus {
  background-color: #f0f0f0;
  outline: none;
}

/* Preview styling */
#preview > div {
  margin-bottom: 5px;
}
</style>

<!-- document_uploader.php -->

<div id="uploadModal" class="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle" style="display: none;">
  <div class="modalContent">
    <button class="closeButton" onclick="cancelUploadModal()" aria-label="Close Upload Modal">&times;</button>
    <h2 id="uploadModalTitle">Upload Document(s)</h2>
    <p id="uploadModalMessage">You can upload up to 2 files.</p>
    
    <!-- Drag & Drop Zone -->
    <div id="dropZone" tabindex="0" aria-label="Drag and drop files here or click to select files" class="dropZone">
      <p>Drag and drop files here</p>
      <p>or</p>
      <button type="button" 
        title="Supported file types: Documents, PDFs<?php if ($config[$deployment]['handles_images']) echo ', and Images'; ?>"
        onclick="document.getElementById('fileInput').click()">Select Files</button>
      <input id="fileInput" 
             type="file" 
             name="uploadDocument[]" 
             accept=".csv,.xlsx,.xls,.pdf,.docx,.pptx,.txt,.md,.json,.xml<?php if ($config[$deployment]['handles_images']) echo ',.png,.jpg,.jpeg,.gif'; ?>"
             multiple 
             style="display:none" 
             onchange="handleFiles(this.files)" />
    </div>
    
    <!-- Preview of Selected Files -->
    <div id="preview"></div>
    
    <!-- Upload Action -->
    <button type="button" style="float:right;" onclick="submitUploadForm()">Upload</button>
  </div>
</div>

<script>
// Global variables
let uploadedFiles = [];  // files selected during this upload session
// documentsLength should already be defined globally; if not, you can set a default:
window.documentsLength = window.documentsLength || 0;

const defaultMaxUploads = <?php echo (int)$config['app']['default_max_uploads']; ?>;
let maxUploads = defaultMaxUploads; // will be updated based on workflow selection

// Function to open the upload modal with dynamic configuration
function old_defunct_openUploadModal() {
  console.log("openUploadModal() triggered");

  // Check for a selected workflow and inspect its config values.
  if (window.selectedWorkflow && window.selectedWorkflow.config) {
    console.log("Found selectedWorkflow.config:", window.selectedWorkflow.config);
    
    // Set the maximum number of uploads.
    if (window.selectedWorkflow.config["single-text-fileupload"]) {
      maxUploads = 1;
      console.log("maxUploads set to 1 based on 'single-text-fileupload'");
    } else if (window.selectedWorkflow.config["three-fileupload"]) {
      maxUploads = 3;
      console.log("maxUploads set to 3 based on 'three-fileupload'");
    } else {
      maxUploads = defaultMaxUploads;
      console.log("No specific file upload limit found; using default maxUploads =", defaultMaxUploads);
    }
    
    // Update the modal title if provided.
    if (window.selectedWorkflow.config["modal-upload-title"]) {
      document.getElementById('uploadModalTitle').textContent = window.selectedWorkflow.config["modal-upload-title"];
      console.log("Updated modal title to:", window.selectedWorkflow.config["modal-upload-title"]);
    } else {
      document.getElementById('uploadModalTitle').textContent = "Upload Document(s)";
      console.log("Using default modal title: 'Upload Document(s)'");
    }
    
    // Update the instruction message if provided.
    if (window.selectedWorkflow.config["modal-upload-instruction"]) {
      document.getElementById('uploadModalMessage').textContent = window.selectedWorkflow.config["modal-upload-instruction"];
      console.log("Updated modal instruction to:", window.selectedWorkflow.config["modal-upload-instruction"]);
    } else {
      const remaining = maxUploads - window.documentsLength;
      const defaultMessage = remaining === 1 
        ? 'You can upload 1 more document.' 
        : `You can upload up to ${remaining} file${remaining > 1 ? 's' : ''}.`;
      document.getElementById('uploadModalMessage').textContent = defaultMessage;
      console.log("No custom instruction; using default message:", defaultMessage);
    }
    
    // Optionally update the upload button text if provided.
    if (window.selectedWorkflow.config["modal-upload-button"]) {
      const uploadBtn = document.querySelector('button[onclick="submitUploadForm()"]');
      if (uploadBtn) {
        uploadBtn.textContent = window.selectedWorkflow.config["modal-upload-button"];
        console.log("Updated upload button text to:", window.selectedWorkflow.config["modal-upload-button"]);
      } else {
        console.log("Upload button not found to update text.");
      }
    } else {
      console.log("No custom upload button text provided.");
    }
  } else {
    // No workflow selected or no config available.
    maxUploads = defaultMaxUploads;
    console.log("No workflow selected; using default maxUploads =", maxUploads);
    document.getElementById('uploadModalTitle').textContent = "Upload Document(s)";
    const remaining = maxUploads - window.documentsLength;
    document.getElementById('uploadModalMessage').textContent = remaining === 1 
      ? 'You can upload 1 more document.' 
      : `You can upload up to ${remaining} file${remaining > 1 ? 's' : ''}.`;
  }
  
  const currentDocs = window.documentsLength || 0;
  console.log("Documents length =", currentDocs, "and maxUploads =", maxUploads);

  // Prevent opening if already at max capacity.
  if (currentDocs >= maxUploads) {
    alert(`You have ${currentDocs} document(s) uploaded. You cannot add more.`);
    console.log("Upload modal not opened: document limit reached.");
    return;
  }

  // Open the modal.
  const modal = document.getElementById('uploadModal');
  modal.style.display = 'flex';
  console.log("Upload modal opened (display set to 'flex').");

  // Reset file selection and update preview.
  uploadedFiles = [];
  updatePreview();
  console.log("Reset uploadedFiles and called updatePreview().");
}

/**
 * Hides the upload modal *and* cleans up the chat if we're in a workflow.
 * Bound only to the cancel (“×”) button.
 */
function cancelUploadModal() {
  // 1) close it
  closeUploadModal();

  // 2) if we're mid-workflow, delete the chat:
  if (window.isWorkflowFlow) {
    const path   = window.location.pathname.replace(/\/$/, '');
    const chatId = path.substring(path.lastIndexOf('/') + 1);

    deleteChat(chatId, '', {
      silent: true,
      hard:   true
    });

    // clear the flag so further closes don’t kill the chat
    window.isWorkflowFlow = false;
  }
}


/**
 * Just hides the upload modal.
 */
function closeUploadModal() {
  const modal = document.getElementById('uploadModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function handleFiles(files) {
  let fileArray = Array.from(files);
  console.log("handleFiles() triggered. Incoming files:", fileArray);
  console.log("Current documentsLength:", window.documentsLength);
  
  const newTotal = window.documentsLength + uploadedFiles.length + fileArray.length;
  console.log("Calculated newTotal =", newTotal, "and maxUploads =", maxUploads);
  if (newTotal > maxUploads) {
    if (window.documentsLength > 0) {
      alert(`You already have ${window.documentsLength} document(s). You can only upload up to ${maxUploads - window.documentsLength} more.`);
      console.log("Exceeded upload limit; alerting user (existing documents > 0).");
    } else {
      alert(`You can only upload up to ${maxUploads} documents.`);
      console.log("Exceeded upload limit; alerting user (no existing documents).");
    }
    return;
  }

  // Safe to add files.
  uploadedFiles = uploadedFiles.concat(fileArray);
  console.log("Files added. Now uploadedFiles:", uploadedFiles);
  updatePreview();

  document.getElementById('fileInput').value = "";
}

function updatePreview() {
  const preview = document.getElementById('preview');
  preview.innerHTML = "";
  uploadedFiles.forEach((file, index) => {
    const fileDiv = document.createElement('div');
    fileDiv.textContent = file.name;
    
    const removeBtn = document.createElement('button');
    // Instead of using textContent "Remove", we set innerHTML to the trash icon SVG.
    removeBtn.innerHTML = TRASH_SVG_BLACK;
    removeBtn.style.marginLeft = "10px";
    // Optionally, you can add additional styling such as removing the button border:
    removeBtn.style.border = "none";
    removeBtn.style.background = "none";
    removeBtn.style.cursor = "pointer";
    
    removeBtn.onclick = () => {
      console.log("Removing file at index", index, "with name:", file.name);
      uploadedFiles.splice(index, 1);
      updatePreview();
    };
    
    fileDiv.appendChild(removeBtn);
    preview.appendChild(fileDiv);
  });
  console.log("updatePreview() finished; preview updated.");
}

function submitUploadForm() {
  console.log("--------- submitUploadForm() START ---------");
  
  // Check current document count.
  const currentDocs = window.documentsLength || 0;
  console.log("Current documentsLength:", currentDocs);
  console.log("Uploaded files count (before adding new files):", uploadedFiles.length);
  
  // Check if the new upload would exceed maxUploads.
  if (currentDocs + uploadedFiles.length > maxUploads) {
    alert(`You can only have up to ${maxUploads} document(s) in total.`);
    console.log("Upload aborted: Exceeds maxUploads", maxUploads, "Current + New =", currentDocs + uploadedFiles.length);
    console.log("--------- submitUploadForm() END (exceed limit) ---------");
    return;
  }

  // Check if any files are selected.
  if (uploadedFiles.length === 0) {
    alert("Please select at least one file to upload.");
    console.log("Upload aborted: No files selected");
    console.log("--------- submitUploadForm() END (no files selected) ---------");
    return;
  }

  // Get the upload button.
  const uploadButton = document.querySelector('button[onclick="submitUploadForm()"]');
  if (!uploadButton) {
    console.error("uploadButton not found!");
    console.log("--------- submitUploadForm() END (no upload button) ---------");
    return;
  }
  
  // Disable upload button and update its text.
  uploadButton.disabled = true;
  uploadButton.textContent = "Uploading...";
  console.log("Upload button disabled and text changed to 'Uploading...'");

  // Build FormData.
  const formData = new FormData();
  formData.append('chat_id', chatId);
  console.log("Appended chat_id:", chatId);

  // Append workflow info if available.
  if (window.selectedWorkflow) {
    const workflowData = JSON.stringify(window.selectedWorkflow);
    formData.append('selected_workflow', workflowData);
    console.log("Appended selected_workflow to FormData:", workflowData);
  } else {
    console.log("No window.selectedWorkflow found.");
  }

  // Append each file.
  uploadedFiles.forEach((file, index) => {
    formData.append('uploadDocument[]', file);
    console.log(`Appended file ${index}:`, file.name);
  });
  console.log("FormData building complete.");

  // Log the FormData keys (Note: this is for debugging; FormData cannot be stringified easily).
  for (let key of formData.keys()) {
    console.log("FormData key:", key);
  }

// Send the AJAX request via fetch.
console.log("Initiating fetch to 'upload.php' with FormData...");
fetch('upload.php', {
  method: 'POST',
  body: formData,
  headers: {
    'X-Requested-With': 'XMLHttpRequest'
  }
})
.then(response => {
  console.log("Received response with status:", response.status);
  return response.json();
})
.then(result => {
  console.log("Upload successful. Received result:", result);

  // 1) If it's a brand-new chat, just redirect
  if (result.new_chat) {
    console.log(
      "Result indicates new_chat. Redirecting with chat_id:", 
      result.chat_id
    );
    window.location.href = 
      "/" + application_path + "/" + encodeURIComponent(result.chat_id);
    console.log("--------- submitUploadForm() END (redirect new_chat) ---------");
    return;
  }

  const docNames = uploadedFiles.map(file => file.name);
  console.log("Docs just uploaded:", docNames);

  const uploadedDocs = Array.isArray(result.uploaded_documents) ? result.uploaded_documents : [];
  const queuedDocs   = uploadedDocs.filter(doc => doc && doc.queued);

  // 3) Close the upload modal
  closeUploadModal();
  console.log("Upload complete; modal closed.");
  fetchAndUpdateChatTitles($('#search-input').val(), false);

  const workflowMode = ($('#exchange_type').val() === 'workflow');
  let workflowPrompt = '';
  let workflowCallback = null;

  if (workflowMode) {
    console.log("Workflow mode detected. Preparing follow-up prompt logic...");

    workflowPrompt =
        window.selectedWorkflow?.config["prompt-replacement-text"]
      || window.selectedWorkflow?.prompt
      || "";

    if (docNames.length) {
      workflowPrompt += " " + docNames.join(", ");
      console.log("Extended workflow prompt with docs:", workflowPrompt);
    }

    workflowCallback = () => {
      const userMessageElem = document.getElementById('userMessage');
      if (userMessageElem) {
        userMessageElem.value = workflowPrompt;
        console.log("Pre-filled userMessage with workflow prompt:", workflowPrompt);
      } else {
        console.error("userMessage element not found!");
      }

      setTimeout(() => {
        const $messageForm = $('#messageForm');
        if ($messageForm.length) {
          console.log("Triggering submit on messageForm after document processing");
          $messageForm.trigger("submit");
          console.log("--------- submitUploadForm() queued workflow submission ---------");
        } else {
          console.error("messageForm not found!");
        }
      }, 100);
    };
  }

  if (queuedDocs.length && typeof window.startDocumentProcessingWatch === 'function') {
    console.log("Documents queued for background processing:", queuedDocs);

    const docNamesForWatcher = queuedDocs.map(doc => doc.name || '');
    const startWatcher = (estimatedSeconds, estimateMeta) => {
      window.startDocumentProcessingWatch(queuedDocs, {
        docNames: docNamesForWatcher,
        onReady: workflowCallback,
        estimatedSeconds: estimatedSeconds,
        estimateMeta: estimateMeta || null
      });

      if (!workflowMode) {
        console.log("Prompt entry disabled until documents finish processing.");
        console.log("--------- submitUploadForm() END (documents queued) ---------");
      } else {
        console.log("Workflow submission deferred until documents finish processing.");
        console.log("--------- submitUploadForm() END (workflow deferred) ---------");
      }
    };

    const estimatePayload = {
      documents: queuedDocs.map(doc => ({
        id: doc.id,
        mime: doc.type || '',
        original_size: doc.original_size ?? null,
        parsed_size: doc.parsed_size ?? null
      }))
    };

    fetch('document_estimate.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(estimatePayload)
    })
      .then(response => {
        if (!response.ok) {
          throw new Error('Estimate request failed with status ' + response.status);
        }
        return response.json();
      })
      .then(data => {
        const estimated = Number(data.total_estimate_sec);
        const estimateMeta = {
          source: data.estimate_source || null,
          total_data_points: data.total_data_points || null
        };
        if (Array.isArray(data.documents)) {
          estimateMeta.per_doc = data.documents;
        }
        if (!Number.isFinite(estimated) || estimated <= 0) {
          startWatcher(null, estimateMeta);
        } else {
          startWatcher(estimated, estimateMeta);
        }
      })
      .catch(error => {
        console.warn('Falling back to default processing estimate:', error);
        startWatcher(null, null);
      });

  } else if (workflowMode && typeof workflowCallback === 'function') {
    workflowCallback();
    console.log("--------- submitUploadForm() END (workflow auto-submit) ---------");
  } else {
    console.log("Workflow mode not active; no auto-submit.");
    console.log("--------- submitUploadForm() END (normal flow) ---------");
  }
})
.catch(error => {
  console.error("Upload error:", error);
  alert("There was an error uploading your document. Please try again.");
  console.log("--------- submitUploadForm() END (error) ---------");
})
.finally(() => {
  // Re-enable the upload button.
  const uploadButton = document.querySelector('button[onclick="submitUploadForm()"]');
  if (uploadButton) {
    uploadButton.disabled = false;
    uploadButton.textContent = "Upload";
    console.log("Upload button re-enabled.");
  }
});
}

// DRAG & DROP HANDLING
const dropZone = document.getElementById('dropZone');
function preventDefaults(e) {
  e.preventDefault();
  e.stopPropagation();
  console.log("preventDefaults triggered for event:", e.type);
}
function highlight(e) {
  dropZone.classList.add('highlight');
  console.log("highlight() added 'highlight' class for event:", e.type);
}
function unhighlight(e) {
  dropZone.classList.remove('highlight');
  console.log("unhighlight() removed 'highlight' class for event:", e.type);
}
function handleDrop(e) {
  console.log("handleDrop() triggered.");
  let dt = e.dataTransfer;
  let files = dt.files;
  handleFiles(files);
}
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
  dropZone.addEventListener(eventName, preventDefaults, false);
});
dropZone.addEventListener('dragenter', highlight, false);
dropZone.addEventListener('dragover', highlight, false);
dropZone.addEventListener('dragleave', unhighlight, false);
dropZone.addEventListener('drop', unhighlight, false);
dropZone.addEventListener('drop', handleDrop, false);
</script>
