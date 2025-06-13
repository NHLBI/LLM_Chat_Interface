<?php
$workflows = get_all_workflows();

#echo '<pre>'.print_r($workflows,1).'</pre>';

if (!empty($workflows['config_label'])) {
    $config_labels = explode(',',$workflows['config_label']);
    $config_descriptions = explode(',',$workflows['config_description']);
    for($i=0;$i<count($configLabels);$i++) {
        $workflow_config[$config_labels[$i]] = $config_descriptions[$i];
    }
}
#echo '<pre>'.print_r($workflow_config,1).'</pre>'; 

?>

<div class="cannedModalWindow" role="dialog" aria-modal="true" aria-labelledby="cannedModalTitle" style="display: none;">
  <div class="modelsModalContent">
    <!-- Close button -->
    <button class="closeCanned" onclick="cancelCannedModal()" aria-label="Close Canned Operation Modal">&times;</button>

    <h4 id="cannedModalTitle">Select a Workflow</h4>

<p>
<b>Disclaimer</b>: These workflows use generative AI to organize and present information based on the prompt designed by NHLBI program officials. While each workflow has been reviewed and tested for accuracy, the results may contain errors or omissions.
</p>
<p>
You are responsible for reviewing all AI-generated outputs and making decisions based on your own professional judgment. These tools are designed to support your workflow, not to replace human oversight.
</p>
<p>
For questions or suggestions on new workflows, please contact <a href="mailto:NHLBI_AIWorkflows@nhlbi.nih.gov">NHLBI_AIWorkflows@nhlbi.nih.gov</a>.
</p>

    <div class="canned-options">
      <?php foreach($workflows as $workflow): ?>
        <button type="button" 
                class="canned-option" 
                data-workflow-id="<?php echo htmlspecialchars($workflow['id']); ?>" 
                data-prompt="<?php echo htmlspecialchars($workflow['description']); ?>" 
                data-action="summarize"
                data-config-label="<?php echo htmlspecialchars($workflow['config_label']); ?>"
                data-config-description="<?php echo htmlspecialchars($workflow['config_description']); ?>"
                >
          <h5><?php echo htmlspecialchars($workflow['title']); ?></h5>
          <p><?php echo htmlspecialchars($workflow['description']); ?></p>
        </button>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<style>
.cannedModalWindow {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1001;
}
.canned-options {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1.5rem;
}
.canned-option {
    display: block;
    width: 100%;
    padding: 1rem;
    border: 1px solid #a2a8b0;
    border-radius: 0.5rem;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: all 0.2s ease;
}
.canned-option:hover {
    border-color: #93c5fd;
    background-color: #f8fafc;
}
.canned-option h5 {
    margin: 0 0 0.5rem 0;
    font-size: 1.1rem;
    font-weight: 600;
}
.canned-option p {
    margin: 0;
    font-size: 0.9rem;
    color: #475569;
}
#cannedModalTitle {
    text-align: center;
    padding-bottom: 10px;
}
</style>

<script>
function startNewWorkflow() {
    $.ajax({
        type: "POST",
        url: "new_chat.php",
        dataType: 'json',
        success: function(response) {
            var newChatId = response.chat_id;
            // Append a query parameter to indicate workflow mode, for instance:
            window.location.href = "/" + application_path + "/" + newChatId + "?workflow=1";
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('workflow') === '1') {
    window.isWorkflowFlow = true;          // ← new flag
    showCannedModal();

    // remove it from the URL
    const newURL = window.location.protocol + "//"
                 + window.location.host
                 + window.location.pathname;
    window.history.replaceState({}, document.title, newURL);
  }
});

</script>
