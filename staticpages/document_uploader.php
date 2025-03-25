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
    <button class="closeButton" onclick="closeUploadModal()" aria-label="Close Upload Modal">&times;</button>
    <h2 id="uploadModalTitle">Upload Document(s)</h2>
    <p>You can upload up to 2 files.</p>
    
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
             accept=".pdf,.docx,.pptx,.txt,.md,.json,.xml<?php if ($config[$deployment]['handles_images']) echo ',.png,.jpg,.jpeg,.gif'; ?>"
             multiple 
             style="display:none" 
             onchange="handleFiles(this.files)" />
    </div>
    
    <!-- Preview of Selected Files -->
    <div id="preview"></div>
    
    <!-- Upload Action -->
    <button type="button" onclick="submitUploadForm()">Upload</button>
  </div>
</div>

<script>
let uploadedFiles = [];
//let documentsLength = 0; // Keep track of how many documents are in the current chat


function openUploadModal() {
  // Update the global documentsLength variable instead of creating a local one
  //documentsLength = currentChat && currentChat.documents
  //    ? Object.keys(currentChat.documents).length
  //   : 0;

  console.log(`Documents Length 1: ${documentsLength}`);

  // If the user already has 2 documents, do not let them upload more
  if (documentsLength >= 2) {
    alert(`You have ${documentsLength} documents uploaded. You cannot add more.`);
    return; 
  }

  // Update the text message to inform the user of how many more files they can upload
  const modalTextElement = document.querySelector('#uploadModal p');
  if (modalTextElement) {
    const remaining = 2 - documentsLength;
    modalTextElement.textContent = remaining === 1 
      ? 'You can upload 1 more document.' 
      : 'You can upload up to 2 files.';
  }

  const modal = document.getElementById('uploadModal');
  modal.style.display = 'flex';
  // Reset any previous selections
  uploadedFiles = [];
  updatePreview();
}

// Close modal
function closeUploadModal() {
  document.getElementById('uploadModal').style.display = 'none';
}

function handleFiles(files) {
  let fileArray = Array.from(files);

  // Check if new total would exceed 2
  // documentsLength: how many are already on server
  // uploadedFiles.length: how many the user has already selected in this modal
  // fileArray.length: how many new files they're trying to add this time
  console.log(`Documents Length 2: ${documentsLength}`);
  const newTotal = documentsLength + uploadedFiles.length + fileArray.length;
  if (newTotal > 2) {
    if (documentsLength > 0) alert(`You already have ${documentsLength} document(s). You can only upload up to ${2 - documentsLength} more.`);
    else alert(`You can only upload up to 2 documents.`);
    return;
  }

  // It's safe to add these files
  uploadedFiles = uploadedFiles.concat(fileArray);
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
    removeBtn.textContent = "Remove";
    removeBtn.style.marginLeft = "10px";
    removeBtn.onclick = () => {
      uploadedFiles.splice(index, 1);
      updatePreview();
    };
    fileDiv.appendChild(removeBtn);
    preview.appendChild(fileDiv);
  });
}

function submitUploadForm() {
  // Another optional check:
  if (documentsLength + uploadedFiles.length > 2) {
    alert(`You can only have up to 2 documents in total.`);
    return;
  }

  if (uploadedFiles.length === 0) {
    alert("Please select at least one file to upload.");
    return;
  }

  // Disable the upload button immediately to prevent duplicates
  const uploadButton = document.querySelector('button[onclick="submitUploadForm()"]');
  uploadButton.disabled = true;
  uploadButton.textContent = "Uploading...";

  // Build FormData
  const formData = new FormData();
  formData.append('chat_id', chatId);

  // Add each selected file
  uploadedFiles.forEach((file) => {
    formData.append('uploadDocument[]', file);
  });
  
  // Send via Fetch
  fetch('upload.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => response.json())
  .then(result => {
    console.log('Upload successful:', result);
    // Redirect if a new chat was created
    if (result.new_chat) {
      window.location.href = "/" + application_path + "/" + encodeURIComponent(result.chat_id);
    } else {
      closeUploadModal();
      // Optionally refresh UI...
      fetchAndUpdateChatTitles($('#search-input').val(), false);
    }
  })
  .catch(error => {
    console.error('Upload error:', error);
    alert("There was an error uploading your document. Please try again.");
  })
  .finally(() => {
    // Re-enable the upload button after processing is complete
    uploadButton.disabled = false;
    uploadButton.textContent = "Upload";
  });
}

function old_submitUploadForm() {
  // Another optional check:
  // If documentsLength + uploadedFiles.length > 2, also fail 
  if (documentsLength + uploadedFiles.length > 2) {
    alert(`You can only have up to 2 documents in total.`);
    return;
  }

  if (uploadedFiles.length === 0) {
    alert("Please select at least one file to upload.");
    return;
  }

  // Build FormData
  const formData = new FormData();

  // Retrieve chat_id from the current chat context (or URL)
  formData.append('chat_id', chatId);

  // Add each selected file
  uploadedFiles.forEach((file) => {
    formData.append('uploadDocument[]', file);
  });
  
  // Send via Fetch
  fetch('upload.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest' // Mark request as AJAX
    }
  })
  .then(response => response.json())
  .then(result => {
    console.log('Upload successful:', result);
    // If a new chat was created, redirect the page to the new chat URL
    if (result.new_chat) {
      window.location.href = "/"+application_path+"/" + encodeURIComponent(result.chat_id);
    } else {
      closeUploadModal();
      // Optionally refresh UI...
      fetchAndUpdateChatTitles($('#search-input').val(), false);
    }
  })
  .catch(error => {
    console.error('Upload error:', error);
  });
}

// DRAG & DROP HANDLING
const dropZone = document.getElementById('dropZone');

// Prevent default drag behaviors
function preventDefaults(e) {
  e.preventDefault();
  e.stopPropagation();
}

// Highlight drop zone when item is dragged over it
function highlight(e) {
  dropZone.classList.add('highlight');
}

// Remove highlight when item is dragged out or dropped
function unhighlight(e) {
  dropZone.classList.remove('highlight');
}

// Handle dropped files
function handleDrop(e) {
  let dt = e.dataTransfer;
  let files = dt.files;
  handleFiles(files);
}

// Set up the drag & drop event listeners
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
  dropZone.addEventListener(eventName, preventDefaults, false);
});
dropZone.addEventListener('dragenter', highlight, false);
dropZone.addEventListener('dragover', highlight, false);
dropZone.addEventListener('dragleave', unhighlight, false);
dropZone.addEventListener('drop', unhighlight, false);
dropZone.addEventListener('drop', handleDrop, false);
</script>

