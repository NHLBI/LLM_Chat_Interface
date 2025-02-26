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
      <button type="button" onclick="document.getElementById('fileInput').click()">Select Files</button>
      <input id="fileInput" 
             type="file" 
             name="uploadDocument[]" 
             accept=".pdf,.docx,.pptx,.txt,.md,.json,.xml,.png,.jpg,.jpeg,.gif" 
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
let documentsLength = 0; // Keep track of how many documents are in the current chat

function openUploadModal() {
  // Suppose currentChat is an object containing { documents: {...} }
  // Safely get the number of documents:
  const documentsLength = currentChat && currentChat.documents
      ? Object.keys(currentChat.documents).length
      : 0;

  console.log(`Documents Length: ${documentsLength}`);

  // If the user already has 2 documents, do not let them upload more
  if (documentsLength >= 2) {
    alert(`You have ${documentsLength} documents uploaded. You cannot add more.`);
    return; 
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
  const newTotal = documentsLength + uploadedFiles.length + fileArray.length;
  if (newTotal > 2) {
    if (documentsLength > 0) alert(`You already have ${documentsLength} document(s). You can only upload up to ${2 - documentsLength} more.`);
    else alert(`You can only upload up to 2 documents.`);
    return;
  }

  // It's safe to add these files
  uploadedFiles = uploadedFiles.concat(fileArray);
  updatePreview();
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
    body: formData
  })
  .then(response => response.text())
  .then(result => {
    console.log('Upload successful:', result);
    closeUploadModal();
    // Optionally refresh UI...
    fetchAndUpdateChatTitles($('#search-input').val(), false);
  })
  .catch(error => {
    console.error('Upload error:', error);
  });
}
</script>

