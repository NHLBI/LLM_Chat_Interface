<?php
// Include the library functions and the database connection
require_once 'lib.required.php'; 
require_once 'db.php'; 

$username = $_SESSION['user_data']['name'];

$emailhelp = $config['app']['emailhelp'];

$deployments_json = array();
foreach(array_keys($models) as $m) {
    $deployments_json[$m] = $config[$m];
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['app']['app_title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.v1.02.css" rel="stylesheet">
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.4.0/styles/default.min.css">
    <script src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.4.0/highlight.min.js"></script>
    <script>
        var application_path = "<?php echo $application_path; ?>";
        var deployments = <?php echo json_encode($deployments_json); ?>;

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
            <p id="username"><span class="greeting">Hello </span><span class="user-name"><?php echo $username; ?></span> <a title="Log out of the chat interface" href="logout.php" class="logout-link" style="display:inline-block;">Logout</a></p>
        </div>
    </div> <!-- End Header Row -->



    <div class="row flex-grow-1"> <!-- Begin the Content Row -->

        <nav class="col-12 col-md-2 d-flex align-items-start flex-column menu">

            <!-- Menu content here -->
            <div class="p-2 "><!-- Start Menu top content -->
                <p class="newchat"><a title="Create new chat" href="javascript:void(0);" onclick="startNewChat()">+&nbsp;&nbsp;New Chat</a></p>
                <?php
                $path = get_path();
                foreach ($all_chats as $chat) {
                    $class= '';
                    if (!empty($chat_id) && $chat['id'] == $chat_id) {
                        $class = 'current-chat';  // This is the currently active chat
                        $chatTitle = htmlspecialchars($chat['title']);
                    }
                    echo '<div class="chat-item '.$class.'" id="chat-' . htmlspecialchars($chat['id']) . '">';

                    echo '<a class="chat-link chat-title" title="Load chat into context window" href="/'.$application_path.'/' . htmlspecialchars($chat['id']) . '">' . htmlspecialchars($chat['title']) . '</a>';
                    echo '<img class="chat-icon edit-icon" src="images/chat_edit.png" alt="Edit this chat" title="Edit this chat">';
                    echo '<img class="chat-icon delete-icon" src="images/chat_delete.png" alt="Delete this chat" title="Delete this chat">';
                    echo '</div>';
                }
                ?>

            </div> <!-- End Menu top content -->
    

            <div class="mt-auto p-2"><!-- Start Menu bottom content -->
                <!-- Adding the feedback link -->
                <p class="feedback "><?php echo $config['app']['feedback_text']; ?>
                </br>
                </br>
                <a title="Open a link to the Teams interface" href="<?php echo $config['app']['teams_link']; ?>" target="_blank">Connect in Teams</a></p>
                <p class=""><a title="Open a new window to submit feedback" href="<?php echo $config['app']['feedback_link']; ?>" target="_blank">Submit Feedback</a></p>
                <p class=""><a title="Open the training video in a new window" href="<?php echo $config['app']['video_link']; ?>" target="_blank">Training Video</a></p>
                <p><a title="Open the disclosure information in a new window" href="<?php echo $config['app']['disclosure_link']; ?>" target="_Blank">Vulnerability Disclosure</a></p>
            </div><!-- End Menu bottom content -->


        </nav> <!-- End the menu column -->

        <main id="main-content" class="col-12 col-md-10 d-flex align-items-start flex-column main-content">
            <h1 class="print-title"><?php echo $chatTitle;?></h1>



            <!-- Main content here -->
            <div id="messageList" class="p-2 maincolumn maincol-top chat-container"><!-- Flex item chat body top -->
                    <!-- Chat messages will be added here -->
           </div><!-- End Flex item chat body top -->

            <div class="maincolumn maincol-bottom"><!-- Chat body bottom -->
                <form id="messageForm">
                    <textarea class="form-control" id="userMessage" aria-label="Main chat textarea" placeholder="Type your message..." rows="4" ></textarea>
                </form>

                <form onsubmit="saveMessage()" id="model_select" action="" method="post" onsubmit="saveMessage()" style="display: inline-block; margin-left: 20px; margin-right: 10px; margin-top: 15px; border-top: 1px solid white; ">
                    <label for="model">Select Model</label>: <select title="Choose between available chat models" name="model" onchange="document.getElementById('model_select').submit();">
                        <?php
                        foreach ($models as $m => $label) {
                            $sel = ($m == $_SESSION['deployment']) ? 'selected="selected"' : '';
                            echo '<option value="'.$m.'"'.$sel.'>'.$label.'</option>'."\n";
                        }
                        ?>
                    </select>
                </form>
                <form onSubmit="saveMessage();" method="post" action="upload.php" id="document-uploader" enctype="multipart/form-data" style="display: inline-block; margin-top: 15px; margin-left: 30px;">
                    <!-- Hidden input for chat_id -->
                    <input type="hidden" name="chat_id" aria-label="Hidden field with Chat ID" value="<?php echo htmlspecialchars($_GET['chat_id']); ?>">

                    <?php if (!empty($_SESSION['document_name'])): ?>
                        <p>Uploaded file: <span style="color: salmon;"><?php echo htmlspecialchars($_SESSION['document_name']); ?></span>
                            <a href="upload.php?remove=1&chat_id=<?php echo htmlspecialchars($_GET['chat_id']); ?>" style="color: blue">Remove</a>
                        </p>
                    <?php else: ?>
                        <input type="file" name="pdfDocument" aria-label="File upload button" accept=".pdf,.docx,.pptx,.txt,.md,.json,.xml" style="width:15em;" required onchange="this.form.submit()" />
                    <?php endif; ?>

                </form>
                <form style="display: inline-block; float: right; margin-top: 15px; margin-right: 30px;">
                    <button title="Print the existing chat session" aria-label="Print button" onClick="printChat()" id="printButton">Print</button>
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
        var chatId = <?php echo json_encode(isset($_GET['chat_id']) ? $_GET['chat_id'] : null); ?>;
        var user = <?php echo json_encode($user); ?>;
    </script>
    <script src="script.v1.02.js"></script>
<script>
function printChat() {
window.print();
}

// When the page is loaded, check if there's a saved userMessage and display it
/*document.addEventListener('DOMContentLoaded', (event) => {
var savedMessage = localStorage.getItem('userMessage');
if (savedMessage) {
document.getElementById('userMessage').value = savedMessage;
}
});

// Each time the userMessage is updated, save it in the local storage
document.getElementById('userMessage').addEventListener('input', (event) => {
localStorage.setItem('userMessage', event.target.value);
});

// After the form is submitted, clear the saved message
document.getElementById('messageForm').addEventListener('submit', function() {
localStorage.removeItem('userMessage');
});
 */
</script>

</body>
</html>


