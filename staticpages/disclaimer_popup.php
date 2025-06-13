<?php require_once 'staticpages/disclaimer_text.php'; ?>                                                                                        
<!-- disclaimer_text.php -->
<div class="disclaimerWindow" role="dialog" aria-modal="true" aria-labelledby="disclaimerModalTitle">
  <div class="disclaimerModalContent">
    <!-- Close button -->
    <button class="closeButton" onclick="closeAboutUs()" aria-label="Close Disclaimer">&times;</button>
    
    <h4 id="disclaimerModalTitle" style="">About NHLBI Chat</h4>

    <?php echo $topbox; ?>
    <?php echo $maintext; ?>
    
  </div> <!-- /.disclaimerModalContent -->
</div> <!-- /.disclaimerWindow -->

