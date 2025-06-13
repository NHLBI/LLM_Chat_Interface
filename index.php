<?php

#die("<h2>NHLBI Chat is down for a very brief maintenance.</h2>");
// Include the library functions and the database connection
require_once 'lib.required.php'; 
# phpinfo();

$username = $_SESSION['user_data']['name'];

$emailhelp = $config['app']['emailhelp'];

$deployments_json = array();
foreach(array_keys($models) as $m) {
    $deployments_json[$m] = $config[$m];
}

#echo "<pre>".print_r($_SESSION,1)."</pre>"; #die();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['app']['app_title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.v2.02.css" rel="stylesheet">
    <!-- Highlight.js CSS -->
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github-dark.min.css">

    <!-- Highlight.js Library -->
    <script src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>

    <!-- Include marked.js for Markdown parsing -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

  <!-- MathJax configuration -->
    <script>
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']]
        },
        svg: { fontCache: 'global' }
    };
    </script>
    <!-- Load MathJax -->
    <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js" async></script>

    <!-- Initialize Highlight.js -->
    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            hljs.highlightAll();
        });
    </script>



    <!-- Application-specific variables passed from PHP -->
    <script>
        var application_path = "<?php echo $application_path; ?>";
        var deployments = <?php echo json_encode($deployments_json); ?>;
        var sessionTimeout = <?php echo $sessionTimeout * 1000; ?>; // Convert seconds to milliseconds
        var deployment = "<?php echo $deployment; ?>";
        var deployment_label = "<?php echo $models[$deployment]['label']; ?>";
        var host = "<?php echo $config[$deployment]['host'] ?? '' ; ?>";
        var handles_images = "<?php echo $config[$deployment]['handles_images']  ?? ''; ?>";
        var handles_documents = "<?php echo $config[$deployment]['handles_documents']  ?? ''; ?>";
        var temperature = "<?php echo $_SESSION['temperature']  ?? ''; ?>";
        var context_limit = "<?php echo $context_limit; ?>";
        var chatContainer;
        var currentChat;

        var document_name = '';
        var document_type = '';
        var document_source = '';
        var documentsLength = 0;

        var chatId = <?php echo json_encode(isset($_GET['chat_id']) ? $_GET['chat_id'] : null); ?>;
        //console.log("THIS IS THE CHAT ID IN INDEX.PHP: "+chatId)

        var search_term = "<?php echo isset($_SESSION['search_term']) ? htmlspecialchars($_SESSION['search_term'], ENT_QUOTES, 'UTF-8') : ''; ?>";

        const TRASH_SVG = `
        <svg fill="#ffffff" height="18px" width="18px" version="1.1" xmlns="http://www.w3.org/2000/svg" 
             viewBox="0 0 60.167 60.167" xml:space="preserve">
          <path d="M54.5,11.667H39.88V3.91c0-2.156-1.754-3.91-3.91-3.91H24.196c-2.156,0-3.91,1.754-3.91,3.91v7.756H5.667
              c-0.552,0-1,0.448-1,1s0.448,1,1,1h2.042v40.5c0,3.309,2.691,6,6,6h32.75c3.309,0,6-2.691,6-6v-40.5H54.5
              c0.552,0,1-0.448,1-1S55.052,11.667,54.5,11.667z M22.286,3.91c0-1.053,0.857-1.91,1.91-1.91H35.97c1.053,0,1.91,0.857,1.91,1.91
              v7.756H22.286V3.91z M50.458,54.167c0,2.206-1.794,4-4,4h-32.75c-2.206,0-4-1.794-4-4v-40.5h40.75V54.167z M38.255,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S38.255,46.706,38.255,46.153z M29.083,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S29.083,46.706,29.083,46.153z M19.911,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S19.911,46.706,19.911,46.153z"
              stroke="#ffffff" stroke-width="1"/>
        </svg>
        `;

        const TRASH_SVG_BLACK = `
        <svg fill="#333333" height="18px" width="18px" version="1.1" xmlns="http://www.w3.org/2000/svg" 
             viewBox="0 0 60.167 60.167" xml:space="preserve">
          <path d="M54.5,11.667H39.88V3.91c0-2.156-1.754-3.91-3.91-3.91H24.196c-2.156,0-3.91,1.754-3.91,3.91v7.756H5.667
              c-0.552,0-1,0.448-1,1s0.448,1,1,1h2.042v40.5c0,3.309,2.691,6,6,6h32.75c3.309,0,6-2.691,6-6v-40.5H54.5
              c0.552,0,1-0.448,1-1S55.052,11.667,54.5,11.667z M22.286,3.91c0-1.053,0.857-1.91,1.91-1.91H35.97c1.053,0,1.91,0.857,1.91,1.91
              v7.756H22.286V3.91z M50.458,54.167c0,2.206-1.794,4-4,4h-32.75c-2.206,0-4-1.794-4-4v-40.5h40.75V54.167z M38.255,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S38.255,46.706,38.255,46.153z M29.083,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S29.083,46.706,29.083,46.153z M19.911,46.153V22.847
              c0-0.552,0.448-1,1-1s1,0.448,1,1v23.306c0,0.552-0.448,1-1,1S19.911,46.706,19.911,46.153z"
              stroke="#333333" stroke-width="1"/>
        </svg>
        `;


    </script>

</head>
<body>

<!-- Navbar for Hamburger Menu -->
<nav class="navbar navbar-dark bg-dark">
    <button class="navbar-toggler" type="button" id="toggleMenu" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
</nav>

<div class="container-fluid"> <!-- start the Container-fluid -->
    <a href="#main-content" class="skip-link">Skip to main content</a>
    

    <div class="row header d-flex align-items-center"> <!-- Header Row -->
        <div class="col d-flex justify-content-start">
            <img src="images/<?php echo $config['app']['app_logo']; ?>" class="logo" alt="<?php echo $config['app']['app_logo_alt']; ?>">
        </div>
        <div class="col d-flex justify-content-center">
            <h1><?php echo $config['app']['app_title']; ?></h1>
        </div>
        <div class="col d-flex justify-content-end">
            <div id="username" class="d-flex align-items-center">
                <span class="greeting">Hello </span>
                <span class="user-name" style="margin-left: 5px;margin-right:10px;"><?php echo $username; ?></span> 

                <!-- Logout Link -->
                <a title="Log out of the chat interface" href="logout.php" class="logout-link" style="display:inline-block;">Logout</a>
            </div>
        </div>
    </div> <!-- End Header Row -->



    <div class="row flex-grow-1"> <!-- Begin the Content Row -->

        <nav class="col-12 col-md-2 d-flex align-items-start flex-column menu">
            <!-- Menu content here -->
            <div class="p-2">
                <!-- Start Menu top content -->
                <!-- Search Input -->
                <input 
                    type="text" 
                    id="search-input" 
                    name="search" 
                    value="<?php echo isset($_SESSION['search_term']) ? htmlspecialchars($_SESSION['search_term'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                    placeholder="Search in chats..."
                >


                <!-- Cancel Button -->
                <button id="cancel-search" style="background: inherit; border: 0 none;" aria-label="Cancel Search">
                    <!-- Cancel Icon SVG -->
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" width="20px" height="20px">
                        <!-- SVG path data -->
                        <path fill="#FFFFFF" d="M 25 2 C 12.309534 2 2 12.309534 2 25 C 2 37.690466 12.309534 48 25 48 C 37.690466 48 48 37.690466 48 25 C 48 
                        12.309534 37.690466 2 25 2 z M 25 4 C 36.609534 4 46 13.390466 46 25 C 46 36.609534 36.609534 46 25 46 C 13.390466 46 4 
                        36.609534 4 25 C 4 13.390466 13.390466 4 25 4 z M 32.990234 15.986328 A 1.0001 1.0001 0 0 0 32.292969 16.292969 L 25 
                        23.585938 L 17.707031 16.292969 A 1.0001 1.0001 0 0 0 16.990234 15.990234 A 1.0001 1.0001 0 0 0 16.292969 17.707031 L 
                        23.585938 25 L 16.292969 32.292969 A 1.0001 1.0001 0 1 0 17.707031 33.707031 L 25 26.414062 L 32.292969 33.707031 A 
                        1.0001 1.0001 0 1 0 33.707031 32.292969 L 26.414062 25 L 33.707031 17.707031 A 1.0001 1.0001 0 0 0 32.990234 15.986328 z"/>

                    </svg>
                </button>

                <!-- Search Icon Button -->
                <button id="open-search" style="background: inherit; border: 0 none;" aria-label="Open Search">
                    <!-- Search Icon SVG -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 50 50">
                        <!-- SVG path data -->
                        <path fill="#FFFFFF" d="M 21 3 C 11.621094 3 4 10.621094 4 20 C 4 29.378906 11.621094 37 21 37 C 24.710938 37 28.140625 35.804688 
                        30.9375 33.78125 L 44.09375 46.90625 L 46.90625 44.09375 L 33.90625 31.0625 C 36.460938 28.085938 38 24.222656 38 
                        20 C 38 10.621094 30.378906 3 21 3 Z M 21 5 C 29.296875 5 36 11.703125 36 20 C 36 28.296875 29.296875 35 21 35 C 
                        12.703125 35 6 28.296875 6 20 C 6 11.703125 12.703125 5 21 5 Z"/>

                    </svg>
                </button>
                <button class="newchat" style="width: 100px; " title="Create new chat" href="javascript:void(0);" onclick="startNewChat()">+&nbsp;&nbsp;New Chat</button>
                <button class="newchat newworkflow" style="margin-left: 10px; width: 130px; " title="Create new chat" href="javascript:void(0);" onclick="startNewWorkflow()">+&nbsp;&nbsp;New Workflow</button>
                

                <!-- Add a new container for chat titles with a class for styling -->
                <div class="chat-titles-container" style="margin-top: 20px;">
                    <!-- Chat titles will be dynamically inserted here by JavaScript -->
                    <div id="searching-indicator" class="spinner" style="display: none;"></div>
                </div>
                <!-- End chat titles container -->
            </div> <!-- End Menu top content -->

            <div class="mt-auto p-2"><!-- Start Menu bottom content -->
                <!-- Session Info Display (for development) -->
                <p id="session-info" style="color: #FFD700; margin-top: 10px; display: none;"></p>

                <!-- Adding the feedback link -->
                <!-- <p class="aboutChat"><a title="About text" href="javascript:void(0);" onclick="showAboutUs()">About NHLBI Chat</a></p> -->
                <p class=""><a title="About text" href="javascript:void(0);" onclick="showAboutUs()">About NHLBI Chat</a></p>
                <p class=""><a title="Contact OSI" href="mailto:nhlbi_dir_sio@nhlbi.nih.gov">Contact OSI</a></p>
                <p><a title="Open the disclosure information in a new window" href="<?php echo $config['app']['disclosure_link']; ?>" target="_Blank">Vulnerability Disclosure</a></p>
            </div><!-- End Menu bottom content -->
        </nav> <!-- End the menu column -->

        <main id="main-content" class="col-12 col-md-10 d-flex align-items-start flex-column main-content">

            <?php require_once 'staticpages/disclaimer_popup.php'; ?>                                                                                        
            <?php require_once 'staticpages/model_text.php'; ?> 
            <?php require_once 'staticpages/document_uploader.php'; ?> 
            <?php require_once 'staticpages/canned_modal.php'; ?> 

            <h1 id="print-title"></h1>



            <!-- Main content here -->
            <div id="messageList" class="p-2 maincolumn maincol-top chat-container" aria-live="polite"><!-- Flex item chat body top -->
                    <!-- Chat messages will be added here -->
           </div><!-- End Flex item chat body top -->

            <div class="maincolumn maincol-bottom"><!-- Chat body bottom -->

                <form id="messageForm" class="chat-input-form">
                  <div class="input-container">
                    <textarea class="form-control" id="userMessage" aria-label="Main chat textarea" 
                              placeholder="Type your message..." rows="4" required></textarea>
                    
<!-- Start of button group -->
<div class="icon-group" style="display: flex; align-items: center; position: absolute; bottom: 10px; right: 10px;">

<?php if ($config[$deployment]['host'] !== 'dall-e') { ?>

    <!-- Paperclip upload button -->
    <button type="button" class="upload-button" 
            onclick="openUploadModal()" 
            aria-label="Upload Document" 
            style="background: none; border: none; cursor: pointer; margin-right: 8px;">
      <img src="images/paperclip.svg" 
           alt="Upload Document" 
           title="Document types accepted: PDF, Word, PPT, text, markdown, images, etc." 
           style="height: 24px; transform: rotate(45deg);">
    </button>

<?php } ?>

    <!-- Send button (always visible) -->
    <button type="submit" class="submit-button" aria-label="Send message"
            style="background: none; border: none; cursor: pointer;">
      <svg xmlns="http://www.w3.org/2000/svg" 
           viewBox="0 0 24 24" 
           fill="currentColor" 
           class="send-icon" 
           style="height: 24px; color: #3b82f6;">
        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
      </svg>
    </button>
</div>




                  </div>
                    <input type="hidden" id="exchange_type" name="exchange_type" value="chat">
                    <input type="hidden" id="custom_config" name="custom_config" value="">

                </form>


                <button style="" title="Select models from a list" aria-label="About models button" onclick="showAboutModels()" id="modelSelectButton">Model: <?php echo $models[$_SESSION['deployment']]['label']; ?></button>
                <!-- <span style="display: inline-block; margin-top:8px;"><a title="About models" href="javascript:void(0);" onclick="showAboutModels()">Select Model</a> -->
                <!--current model: <?php echo $models[$_SESSION['deployment']]['label']; ?></span> -->

<?php if ($config[$deployment]['handles_temperature']) { ?>

                <form onsubmit="saveMessage()" id="temperature_select" action="" method="post" >
                    <label for="temperature">Temperature</label>: <select title="Choose a temperature setting between 0 and 2. A temperature of 0 means the responses will be very deterministic (meaning you almost always get the same response to a given prompt). A temperature of 2 means the responses can vary substantially." name="temperature" onchange="document.getElementById('temperature_select').submit();">
                        <?php
                        foreach ($temperatures as $t) {
                            $sel = ($t == $_SESSION['temperature']) ? 'selected="selected"' : '';
                            echo '<option value="'.$t.'"'.$sel.'>'.$t.'</option>'."\n";
                        }
                        ?>
                    </select>
                </form>

<?php } ?>
<?php if ($config[$deployment]['host'] !== 'dall-e') { ?>

                <!-- File Upload Form -->
                <form onSubmit="saveMessage();" method="post" action="upload.php" id="document-uploader" enctype="multipart/form-data" style="display: inline-block; margin-top: 10px;">
                    <!-- Hidden input for chat_id -->
                    <input type="hidden" name="chat_id" aria-label="Hidden field with Chat ID" value="<?php echo htmlspecialchars($_GET['chat_id']); ?>">

                </form>
<?php } ?>

<?php 
                    if(!empty($_SESSION['error'])) {
                        echo "<script>alert('Error: ".$_SESSION['error']."');</script>";
                        $_SESSION['error']="";
                        unset($_SESSION['error']);
        
                    }
?>

                <form style="display: inline-block; float: right; margin-top:8px; margin-right: 30px;">
                    <button title="Print the existing chat session" aria-label="Print button" onclick="return printChat()" id="printButton">Print</button>
                </form>
            </div><!-- End Chat body bottom -->
        </main> <!-- End the main-content column -->

    </div> <!-- end the Content Row -->
</div> <!-- end the Container-fluid -->
    <div class="waiting-indicator" style="display: none;">
        <img src="images/Ripple-1s-59px.gif" alt="Loading...">
    </div>

<!-- Include Bootstrap JS and its dependencies-->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
    <script>
        document.getElementById('toggleMenu').addEventListener('click', function() {
            document.querySelector('.menu').classList.toggle('active');
        });
        var user = <?php echo json_encode(isset($user) ? $user : null); ?>;
        var tmr = document.getElementById('username');
</script>

    <!-- Include Session Handler JS -->
    <script src="session_handler.js"></script>

<!-- Include application-specific scripts -->
<script src="scripts.v2.05/utilities.js"></script>
<script src="scripts.v2.05/manage_chats.js"></script>
<script src="scripts.v2.05/popup.js"></script>
<script src="scripts.v2.05/ui.js"></script>
<script src="scripts.v2.05/listeners.js"></script>
<script src="scripts.v2.05/user_images.js"></script>
<script src="script.v2.05.js"></script>
<script>
function printChat() {
    // Prevent the default form submission behavior
    event.preventDefault();
    
    // Use window.print() which opens the print dialog
    window.print();
    
    // Return false to prevent any potential page reload
    return false;
}
</script>

</body>
</html>


