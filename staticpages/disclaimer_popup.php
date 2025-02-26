<!-- disclaimer_text.php -->
<div class="disclaimerWindow" role="dialog" aria-modal="true" aria-labelledby="disclaimerModalTitle">
  <div class="disclaimerModalContent">
    <!-- Close button -->
    <button class="closeButton" onclick="closeAboutUs()" aria-label="Close Disclaimer">&times;</button>
    
    <h4 id="disclaimerModalTitle">About NHLBI Chat</h4>

    <p class="newchat" style="text-align: center; margin-top: 20px;">
        <?php echo $config['app']['help_text1']; ?>
        <span style="display: flex; justify-content: space-between; width: 90%; margin: 20px auto;">
            <a title="Open a link to the Teams interface" href="<?php echo $config['app']['teams_link']; ?>" target="_blank">Connect in Teams</a>
            <a title="Open a link to the NHLBI Intranet interface" href="<?php echo $config['app']['intranet_link']; ?>" target="_blank">Overview and Instructions</a>
            <a title="Open the training video in a new window" href="<?php echo $config['app']['video_link']; ?>" target="_blank">Training Video</a>
            <a title="Open a new window to submit feedback" href="<?php echo $config['app']['feedback_link']; ?>" target="_blank">Submit Feedback</a>
        </span>
    </p>
    
<?php require_once 'staticpages/disclaimer_text.php'; ?>                                                                                        
  </div> <!-- /.disclaimerModalContent -->
</div> <!-- /.disclaimerWindow -->

