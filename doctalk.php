<?php
// Load the autoloader and Drupal Kernel
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'vendor/autoload.php'; // Adjust the path to your Drupal installation
$request = Request::createFromGlobals();

// Boot the Drupal Kernel and handle the request
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$response = $kernel->handle($request);

// Get the current user
$currentUser = \Drupal::currentUser();

// Check if the user is authenticated
if ($currentUser->isAnonymous()) {
    header('Location: /access-denied.html'); // Adjust the path to your Drupal installation
    exit;
}

// Handle AJAX requests for message limit and feedback
define('MESSAGE_LIMIT_JSON', __DIR__ . '/doctalk/message_limit.json');
define('MESSAGES_LOG_FILE', __DIR__ . '/doctalk/messages_log.txt'); // Define log file
define('COUNTER_JSON', __DIR__ . '/doctalk/counter.json');

// Function to get the counter data from the JSON file
function get_counter_data() {
    if (!file_exists(COUNTER_JSON)) {
        // Initialize the file if it doesn't exist
        $data = ['good' => 0, 'bad' => 0, 'total' => 0];
        save_counter_data($data);
    }
    $data = file_get_contents(COUNTER_JSON);
    return json_decode($data, true) ?: ['good' => 0, 'bad' => 0, 'total' => 0];
}

// Function to save the counter data to the JSON file
function save_counter_data($data) {
    file_put_contents(COUNTER_JSON, json_encode($data, JSON_PRETTY_PRINT));
}

// Check if a counter update request is being made
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['counterAction'])) {
    $counterData = get_counter_data();

    // Update the counters based on the action
    if ($_POST['counterAction'] === 'incrementGood') {
        $counterData['good']++;
    } elseif ($_POST['counterAction'] === 'incrementBad') {
        $counterData['bad']++;
    } elseif ($_POST['counterAction'] === 'incrementTotal') {
        $counterData['total']++;
    }

    // Save the updated counters
    save_counter_data($counterData);

    // Return the updated counter data as a JSON response
    echo json_encode($counterData);
    exit;
}


function get_message_limit_data() {
    if (!file_exists(MESSAGE_LIMIT_JSON)) {
        return [];
    }
    $data = file_get_contents(MESSAGE_LIMIT_JSON);
    return json_decode($data, true) ?: [];
}

function save_message_limit_data($data) {
    file_put_contents(MESSAGE_LIMIT_JSON, json_encode($data, JSON_PRETTY_PRINT));
}

function append_to_log($logEntry) {
    file_put_contents(MESSAGES_LOG_FILE, $logEntry, FILE_APPEND);
}

$user_id = $currentUser->id();
$data = get_message_limit_data();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'checkLimit') {
        if (!isset($data[$user_id]) || $data[$user_id]['date'] !== date('Y-m-d')) {
            $data[$user_id] = ['date' => date('Y-m-d'), 'count' => 0];
        }

        if ($data[$user_id]['count'] >= 10) {
            echo json_encode(['status' => 'error', 'message' => 'Message limit reached for today.']);
        } else {
            $data[$user_id]['count']++;
            save_message_limit_data($data);

            // Save the user's message to a file
            if (isset($_POST['message']) && !empty(trim($_POST['message']))) {
                $message = trim($_POST['message']);
                $logEntry = date('Y-m-d H:i:s') . " | User ID: {$user_id} | Message: {$message}" . PHP_EOL;
                append_to_log($logEntry);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Message allowed.',
                'count' => $data[$user_id]['count']
            ]);
        }
        exit;
    } elseif ($_POST['action'] === 'getCount') {
        if (!isset($data[$user_id]) || $data[$user_id]['date'] !== date('Y-m-d')) {
            $data[$user_id] = ['date' => date('Y-m-d'), 'count' => 0];
            save_message_limit_data($data);
        }

        echo json_encode([
            'status' => 'success',
            'count' => $data[$user_id]['count']
        ]);
        exit;
    } elseif ($_POST['action'] === 'saveFeedback') {
        if (isset($_POST['logEntry']) && isset($_POST['feedback'])) {
            $logEntry = trim($_POST['logEntry']);
            $feedback = trim($_POST['feedback']);
            $logEntryWithFeedback = $logEntry . " | Feedback: {$feedback}" . PHP_EOL;
            append_to_log($logEntryWithFeedback);

            echo json_encode(['status' => 'success', 'message' => 'Feedback saved successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot Interface</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@2.3.10/dist/purify.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #ffffff;
        }
        p {
            text-align: justify;
            font-size: 16px;
            font-weight: normal;
            color: #696969;
        }
        
        hr {
            
            height: 0.5px;
            background-color: #D3D3D3;
            color: #D3D3D3;
            border: none;
        }
        
        h3 {
            color: #e49b0f;
        }
        
        .header, .chat-container {
            max-width: 800px;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            box-sizing: border-box;
            color: #000000;
        }
        .header {
            background-color: #001b6b;
            color: white;
            padding: 15px 20px;
            text-align: center;
            font-size: 30px;
            font-weight: bold;
        }
            
        .header-orange {
            background-color: #e49b0f;
            color: white;
            padding: 15px 20px;
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            box-sizing: border-box;
            max-width: 800px;
        }
        .chat-container {
            background: #fff;
            padding: 20px;
            margin-top: -10px;
            color: #000000;
        }
        textarea, button {
            box-sizing: border-box;
            width: 100%;
        }
        textarea {
            height: 60px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: none;
            margin-bottom: 10px;
            color: #000000;
            resize: vertical; /* Allow users to resize if needed */
            height: auto;
            font-size: 16px;
        }
        button {
            padding: 10px;
            background-color: #001b6b;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 20px;
            margin-top: 10px;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #e9ab0d;
        }
        button:disabled {
            background-color: #ccc;
        }
        
        #charCount {
            font-size: 12px;
            color: #696969;
        }
            
        #messageCounter {
            text-align: center;
            margin-top: 10px;
            font-weight: bold;
            color: #696969;
        }
        
        #savePdfButton {
            background-color: #001b6b;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 20px;
            padding: 10px;
            transition: background-color 0.3s ease;
        }

        #savePdfButton:hover {
            background-color: #e9ab0d;
            color: white;
        }
        .feedback {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 10px;
            display: none;
        }
        .spinner {
            margin: 10px auto;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        .progress-bar-container {
            position: relative;
            width: 100%;
            height: 20px;
            background: #ddd;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-bar {
            height: 100%;
            width: 0;
            background: #001b6b;
            transition: width 1s linear;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-size: 14px;
            font-weight: bold;
        }   
        .initial-message {
            text-align: center;
            font-size: 16px;
            color: #001b6b;
            font-weight: bold;
        }
        .chat-log {
            margin-top: 20px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #ddd;
        }
        .chat-log .message {
            margin-bottom: 10px;
        }
        .chat-log .user-message {
            font-weight: bold;
            color: #001b6b;
        }
        .chat-log .bot-response {
            color: #333;
        }
        html * {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="header">DocTalk&reg; - AI Expert Assistant in NATO Education and Individual Training</div>
    <div class="chat-container">
        <div style="text-align: center; margin-top: 10px; font-style: italic;"><span style="color: #e9ab0d; font-style:normal"><strong>DocTalk&reg</strong></span> can make mistakes. Check important info!</div>
        <textarea id="userInput" rows="5" maxlength="500" placeholder="Type your question here (max. 500 characters) ..."></textarea>
            <div id="charCount">0/500 characters</div>
        <div id="messageCounter" style="text-align: center; margin-top: 10px; font-weight: bold;">Daily quota - messages sent: 0/10</div>
        <div style="display: flex; gap: 10px;">
            <button id="submitButton">Send</button>
            <button id="clearButton">Clear</button>
        </div>
		<div id="counterDisplay" style="text-align: center; margin: 20px 0; font-size: 16px; color: #696969;">
    	Total questions: <span id="totalCounter">0</span> | 
		Feedback: <span style="color: #008000;">Good answers: </span><span id="goodCounter" style="color: #008000;">0</span> - 
			<span style="color: #e49b0f;">Bad answers: </span><span id="badCounter" style="color: #e49b0f;">0</span>
		</div>
        <div class="feedback" id="feedbackSection">
            <div class="spinner" id="spinner"></div>
            <div id="documentMessage">Searching the knowledgebase: Document 1</div>
            <div class="progress-bar-container">
                <div class="progress-bar" id="progressBar">0%</div>
            </div>
        </div>
        <div class="chat-log" id="chatLog" role="log" aria-live="polite" aria-relevant="additions">
            <div class="message initial-message"><span style="color: #696969;">AI Assistant:</span> <span style="color: #008000;">READY</span> <span style="color: #696969;">- Document Database:</span> <span style="color: #008000;">LOADED</span> <span style="color: #696969;">- OpenAI API: </span><span style="color: #008000;">ACTIVE</span></div>
            <!-- Chat log messages will appear here -->
        </div>
        <button id="savePdfButton">Save as PDF</button>
    </div><br>
    <div class="header-orange">Getting started with DocTalk&reg;: what you need know</div>
    <div class="chat-container"><h3>What is DocTalk&reg;?<h3>
<p>DocTalk&reg; is an AI-powered chatbot integrated into the QA Hub to support quality managers in NATO Education and Training. Built on OpenAI’s API, it uses advanced natural language processing to analyze multiple documents and provide real-time answers to your questions.</p><hr>
        <h3>How does DocTalk&reg; work&reg;?</h3>
        <p><strong>1. Ask questions:</strong> Enter your quality assurance-related queries directly into the chat.</p>
            <p><strong>2. AI at work:</strong> DocTalk&reg; analyzes multiple documents, synthesizes the information, and provides a concise response.</p>
            <p><strong>3. Interactive messaging:</strong> Responses appear in a user-friendly chat interface, making it easy to engage.</p>
            <p><strong>4. PDF export:</strong> Save your conversations as PDFs for documentation, audits, or sharing with your team.</p><hr>
        <h3>Limitations</h3>
        <p>DocTalk&reg; is powerful but not perfect. Here’s what you should keep in mind:</p>

        <p><strong>1. Daily question limit:</strong> Users can ask up to 10 questions per day to maintain fair usage for all QA Hub members.</p>
        <p><strong>2. No conversation memory:</strong> Unlike a full ChatGPT session, the app doesn’t retain the context of previous                questions. Each query is treated independently.</p>
        <p><strong>3. Possible mistakes:</strong> While its algorithms strive for accuracy, DocTalk&reg; might misinterpret questions or provide                incorrect answers. Always verify critical information before using it in decisions or reports.</p>
        <p><strong>4. Document scope:</strong> Answers are based solely on the documents configured for DocTalk&reg;. Questions beyond this         scope may not yield meaningful responses.</p><hr>
        <h3>Why is DocTalk&reg; helpful for Quality Managers?</h3>
        <p><strong>1. Saves time:</strong> Quickly find answers without manually searching through lengthy documents.</p>
        <p><strong>2. Supports decision-making:</strong> Provides relevant insights to assist in evaluations and planning.</p>
        <p><strong>3. Centralized information:</strong> Ensures consistency by referencing a unified knowledge base.</p>
        <p><strong>4. Documentation made easy:</strong> Export chat conversations as PDFs for reporting or reference.</p><hr>
        <h3>Important Reminders</h3>
        <p><strong>1. Check the AI’s work:</strong> DocTalk&reg; is here to assist but is not infallible. Double-check critical answers and         confirm with your team if needed.</p>
        <p><strong>2. Be specific:</strong> Clear, detailed questions result in better, more accurate answers.</p>
        <p><strong>3. Plan your queries:</strong> With a 10-question daily limit, prioritize your most important questions.</p>
        <p><strong>4. Use export option wisely:</strong> Save your chats to avoid re-asking similar questions and to create a record of             interactions.</p><hr>
        <p>DocTalk&reg; can make your work faster and more efficient, but it <strong>works best when combined with your expertise and critical judgment</strong>. If you encounter issues or have feedback, the QA Hub support team is here to help.</p>
    </div>
    <script>
    const API_URL = "https://your_url/api/chat/completions";
    const AUTHORIZATION_TOKEN = "Bearer your_open_webui_API_Key";
		
// Function to fetch and display the counter values
const updateCounterDisplay = async () => {
    try {
        const response = await $.ajax({
            url: '', // Current script handles this request
            type: 'POST',
            data: { counterAction: 'fetch' },
            dataType: 'json'
        });

        // Update the counters on the interface
        document.getElementById('goodCounter').textContent = response.good || 0;
        document.getElementById('badCounter').textContent = response.bad || 0;
        document.getElementById('totalCounter').textContent = response.total || 0;
    } catch (error) {
        console.error('Error fetching counter data:', error);
    }
};

	// Function to increment a specific counter
const incrementCounter = async (action) => {
    try {
        const response = await $.ajax({
            url: '', // Current script handles this request
            type: 'POST',
            data: { counterAction: action },
            dataType: 'json'
        });

        // Update the counters on the interface
        document.getElementById('goodCounter').textContent = response.good || 0;
        document.getElementById('badCounter').textContent = response.bad || 0;
        document.getElementById('totalCounter').textContent = response.total || 0;
    } catch (error) {
        console.error('Error updating counter:', error);
    }
};
        
    const user_id = <?php echo json_encode($user_id); ?>;

// Function to add Good/Bad feedback buttons
const addFeedbackButtons = (logEntry, parentElement) => {
    const feedbackContainer = document.createElement("div");
    feedbackContainer.style.marginTop = "10px";
    feedbackContainer.style.textAlign = "right";
    feedbackContainer.style.display = "flex"; // Use flexbox for inline alignment
    feedbackContainer.style.justifyContent = "flex-end"; // Align buttons to the right
    feedbackContainer.style.gap = "10px"; // Add spacing between buttons

    const goodButton = document.createElement("button");
    goodButton.textContent = "Good";
    goodButton.style.backgroundColor = "#28a745";
    goodButton.style.color = "#fff";
    goodButton.style.border = "none";
    goodButton.style.borderRadius = "4px";
    goodButton.style.padding = "5px 10px";
    goodButton.style.cursor = "pointer";

    const badButton = document.createElement("button");
    badButton.textContent = "Bad";
    badButton.style.backgroundColor = "#dc3545";
    badButton.style.color = "#fff";
    badButton.style.border = "none";
    badButton.style.borderRadius = "4px";
    badButton.style.padding = "5px 10px";
    badButton.style.cursor = "pointer";

    // Function to send feedback to the server
    const sendFeedback = async (feedback) => {
        try {
            const response = await $.ajax({
                url: '', // Current script handles this request
                type: 'POST',
                data: {
                    action: 'saveFeedback',
                    logEntry: logEntry,
                    feedback: feedback
                },
                dataType: 'json'
            });

            if (response.status === 'success') {
            // Change color based on feedback
            const color = feedback === "Good" ? "green" : "red";
            feedbackContainer.innerHTML = `<strong style="color: ${color};">Feedback recorded: ${feedback}</strong>`;
        } else {
            alert(response.message);
        }
        } catch (error) {
            console.error('Error saving feedback:', error);
            alert("An error occurred while saving feedback.");
        }
    };

    // Add event listeners for the buttons
    goodButton.addEventListener("click", () => sendFeedback("Good"));
    badButton.addEventListener("click", () => sendFeedback("Bad"));

    // Append buttons to the container
    feedbackContainer.appendChild(goodButton);
    feedbackContainer.appendChild(badButton);

    // Append the feedback container to the parent element
    parentElement.appendChild(feedbackContainer);
};
    
// Update the message counter dynamically
const updateMessageCounter = (count) => {
    const messageCounter = document.getElementById('messageCounter');
    messageCounter.textContent = `Daily quota - messages sent: ${count}/10`;
};

// Fetch the current message count on page load
const fetchMessageCount = async () => {
    try {
        const response = await $.ajax({
            url: '', // Current script handles this request
            type: 'POST',
            data: { action: 'getCount' },
            dataType: 'json'
        });
        if (response.status === 'success' && response.count !== undefined) {
            updateMessageCounter(response.count);
        } else {
            console.error('Failed to fetch message count:', response.message);
        }
    } catch (error) {
        console.error('Error fetching message count:', error);
    }
};

// Check message limit when sending a message
const checkMessageLimit = async (message) => {
    try {
        const response = await $.ajax({
            url: '', // Current script handles this request
            type: 'POST',
            data: { action: 'checkLimit', message },
            dataType: 'json'
        });
        return response;
    } catch (error) {
        console.error('Error checking message limit:', error);
        return { status: 'error', message: 'Error checking message limit.' };
    }
};

// Adding the character count and input validation
document.addEventListener("DOMContentLoaded", () => {
  const userInput = document.getElementById("userInput");
  const charCount = document.getElementById("charCount");
  const clearButton = document.getElementById("clearButton");

  // Update character count on input
  userInput.addEventListener("input", () => {
    const currentLength = userInput.value.length;
    charCount.textContent = `${currentLength}/500 characters`;
  });

  // Clear input and reset character count on button click
  clearButton.addEventListener("click", () => {
    userInput.value = ""; // Clear the input field
    charCount.textContent = "0/500 characters"; // Reset the character count
  });
});

// Attach event listeners to the feedback and send buttons
document.addEventListener('DOMContentLoaded', () => {
    // Update counter display on page load
    updateCounterDisplay();

    // Add event listeners for "Good" and "Bad" buttons
    document.body.addEventListener('click', (event) => {
        if (event.target.textContent === 'Good') {
            incrementCounter('incrementGood');
        } else if (event.target.textContent === 'Bad') {
            incrementCounter('incrementBad');
        }
    });
});
		
// Add event listeners
document.addEventListener('DOMContentLoaded', fetchMessageCount);

document.getElementById('submitButton').addEventListener('click', async () => {
    const userInput = document.getElementById("userInput").value.trim();
    const spinner = document.getElementById("spinner");
    const progressBar = document.getElementById("progressBar");
    const documentMessage = document.getElementById("documentMessage");
    const feedbackSection = document.getElementById("feedbackSection");
    const submitButton = document.getElementById("submitButton");
    const chatLog = document.getElementById("chatLog");

    if (!userInput) {
        alert("Please enter a message.");
        return;
    }
    
    // Clear input field and reset character count
    userInput.value = "";
    charCount.textContent = "0/500 characters";
    
    // Remove the initial placeholder message if it exists
    const initialMessage = chatLog.querySelector(".initial-message");
    if (initialMessage) {
        chatLog.removeChild(initialMessage);
    }

    // Check message limit and send the message
    const limitResponse = await checkMessageLimit(userInput);
    if (limitResponse.status === 'error') {
        alert(limitResponse.message);
        return;
    }

    if (limitResponse.status === 'success' && limitResponse.count !== undefined) {
		incrementCounter('incrementTotal');
        updateMessageCounter(limitResponse.count);

        // Add user message to chat log
        const userMessage = document.createElement("div");
        userMessage.classList.add("message", "user-message");
        userMessage.textContent = "You: " + userInput;
        chatLog.appendChild(userMessage);

        // Scroll to the bottom of the chat log
        chatLog.scrollTop = chatLog.scrollHeight;

        // Clear input field and reset UI for processing
        document.getElementById("userInput").value = "";
        spinner.style.display = "block";
        feedbackSection.style.display = "flex";
        submitButton.disabled = true;
        progressBar.style.width = "0%";
        progressBar.innerText = "0%";
        documentMessage.innerText = "Searching the knowledgebase: Document 1";

        let progress = 0;
        let currentDocument = 1;

        // Progress bar and document simulation
        const progressInterval = setInterval(() => {
            progress += 2; // Increment progress by 2%
            if (progress <= 100) {
                progressBar.style.width = progress + "%";
                progressBar.innerText = Math.floor(progress) + "%";
            }
            if (progress % 10 === 0 && currentDocument <= 10) {
                documentMessage.innerText = `Searching the knowledgebase: Document ${currentDocument}`;
                currentDocument++;
            }
        }, 1000);

        const payload = {
            model: "name_of_the_model",
            messages: [{ role: "user", content: userInput }],
            files: [
                { type: "file", id: "file-1-id-no" },
                { type: "file", id: "file-2-id-no" },
                { type: "file", id: "file-3-id-no" },
                { type: "file", id: "file-4-id-no" },
                { type: "file", id: "file-5-id-no" },
                { type: "file", id: "file-6-id-no" },
				        { type: "file", id: "file-7-id-no" },
                { type: "file", id: "file-8-id-no" }
            ]
        };

        try {
            const response = await fetch(API_URL, {
                method: "POST",
                headers: {
                    "Authorization": AUTHORIZATION_TOKEN,
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(payload)
            });

            if (response.ok) {
                const result = await response.json();

                if (!result.choices || !result.choices[0] || !result.choices[0].message) {
                    console.error("Unexpected API response structure", result);
                    alert("The server returned an unexpected response. Please try again later.");
                    return;
                }

                const botResponse = result.choices[0].message.content || "No response available.";
                const botResponseHTML = DOMPurify.sanitize(marked.parse(botResponse));

                const botMessage = document.createElement("div");
                botMessage.classList.add("message", "bot-response");
                botMessage.innerHTML = `<strong><span style="color: orange;">DocTalk&reg;:</span></strong> ${botResponseHTML}`;
                chatLog.appendChild(botMessage);

                 // Add feedback buttons for this response
                addFeedbackButtons(
                    `${new Date().toISOString()} | User ID: ${user_id} | Message: ${userInput}`,
                    botMessage
                );
                
                chatLog.scrollTop = chatLog.scrollHeight;
            } else {
                alert("Error: " + (await response.text()));
            }
        } catch (error) {
            alert("An error occurred: " + error.message);
        } finally {
            clearInterval(progressInterval);
            progressBar.style.width = "100%";
            progressBar.innerText = "100%";
            documentMessage.innerText = "Done processing.";
            spinner.style.display = "none";
            submitButton.disabled = false;
        }
    }
});

// Save as PDF functionality with Markdown support
 document.getElementById("savePdfButton").addEventListener("click", () => {
  const chatLog = document.getElementById("chatLog");
  const messages = chatLog.querySelectorAll(".message");
  const { jsPDF } = window.jspdf;
  const pdf = new jsPDF({
    orientation: "portrait",
    unit: "mm",
    format: "a4",
  });

  const margin = 10; // Margins
  const pageWidth = pdf.internal.pageSize.getWidth(); // Total page width
  const pageHeight = pdf.internal.pageSize.getHeight(); // Total page height
  let y = 40; // Start position for content
  const lineHeight = 6; // Line height
  const paragraphSpacing = 4; // Space between paragraphs
  const listItemSpacing = 4; // Space between list items

  // Add the image at the top of the document
  const logoBase64 = "data:image/png;base64,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"; // Replace with your Base64 image string
  pdf.addImage(logoBase64, "PNG", 15, 10, 20, 20); // Adjust x, y, width, height as needed

  // Helper function to wrap text
const addTextToPdf = (text, indent = 0, isBold = false) => {
    // Ensure consistent font size and type
    pdf.setFontSize(12); // Default body font size
    if (isBold) pdf.setFont("helvetica", "bold");
    else pdf.setFont("helvetica", "normal");
    pdf.setTextColor(0); // Ensure text is black

    const wrappedText = pdf.splitTextToSize(text, pageWidth - 2 * margin - indent);
    wrappedText.forEach((line, index) => {
        if (y > pageHeight - 20) { // Check if we need a new page
            pdf.addPage();
            addFooter(pdf.internal.getCurrentPageInfo().pageNumber, pdf.internal.getNumberOfPages());
            y = 20; // Reset y-coordinate for new page
            y += lineHeight + paragraphSpacing; // Add extra spacing after page break

            // Reset font settings after adding a new page
            pdf.setFontSize(12); // Reset font size
            if (isBold) pdf.setFont("helvetica", "bold");
            else pdf.setFont("helvetica", "normal");
            pdf.setTextColor(0); // Reset text color to black
        }

        pdf.text(line, margin + indent, y);
        y += lineHeight;

        // Add paragraph spacing only after the last line of the current block
        if (index === wrappedText.length - 1) {
            y += paragraphSpacing;
        }
    });
};

 const addFooter = (pageNumber, totalPages) => {
    const dateTime = new Date();
    const footerText = `Document generated by NATO QA HUB on ${dateTime.toLocaleDateString()} @ ${dateTime.toLocaleTimeString()} | Page ${pageNumber} of ${totalPages}`;

    // Clear the footer area before adding text
    const pageWidth = pdf.internal.pageSize.getWidth(); // Page width
    const pageHeight = pdf.internal.pageSize.getHeight(); // Page height
    const footerHeight = 10; // Height to clear (adjust if necessary)

    pdf.setFillColor(255, 255, 255); // Set white background
    pdf.rect(0, pageHeight - footerHeight - 2, pageWidth, footerHeight + 2, 'F'); // Clear area

    // Set footer font style
    pdf.setFontSize(10); // Footer font size
    pdf.setFont("helvetica", "normal"); // Footer font style
    pdf.setTextColor(150); // Footer text color (grey)

    // Center the footer text
    const textWidth = pdf.getTextWidth(footerText); // Calculate text width
    const xCentered = (pageWidth - textWidth) / 2; // Center position

    pdf.text(footerText, xCentered, pageHeight - 10); // Place footer at the bottom
};

  // Process each message in the chat log
  messages.forEach((message) => {
    if (message.classList.contains("user-message")) {
      addTextToPdf(message.textContent, 0, true); // Add user message in bold
      y += paragraphSpacing;
    } else if (message.classList.contains("bot-response")) {
      const botHTML = message.innerHTML;
      const tempElement = document.createElement("div");
      tempElement.innerHTML = botHTML;

      addTextToPdf("QA Hub`s DocTalk\u00AE:", 0, false); // Add DocTalk label
      y += paragraphSpacing;

      const elements = Array.from(tempElement.children);
      if (elements.length === 0) {
        addTextToPdf(tempElement.textContent.trim(), 0, false); // Plain text
        y += paragraphSpacing;
      } else {
        elements.forEach((element, index) => {
          if (element.tagName === "P") {
            addTextToPdf(element.textContent.trim());
            y += paragraphSpacing;
          } else if (element.tagName === "OL" || element.tagName === "UL") {
            const listItems = Array.from(element.children);
            listItems.forEach((listItem, listIndex) => {
              const prefix =
                element.tagName === "OL" ? `${listIndex + 1}. ` : "• ";
              const content = listItem.textContent.trim();
              addTextToPdf(`${prefix}${content}`, 5, false); // Add list item
              y += listItemSpacing;
            });
            y += paragraphSpacing;
          }
          if (index === elements.length - 1) {
            y -= paragraphSpacing;
          }
        });
      }
    }
  });

// Add footer to each page
const totalPages = pdf.internal.getNumberOfPages();
for (let i = 1; i <= totalPages; i++) {
    pdf.setPage(i); // Switch to specific page
    addFooter(i, totalPages); // Add footer
}

  // Save the PDF
  pdf.save("chat_log.pdf");
});

// Clear button functionality
document.getElementById("clearButton").addEventListener("click", () => {
document.getElementById("userInput").value = ""; // Clear the input field only
});

// Keep task menus expanded after Undo/Done actions and place Edit after action buttons.
document.addEventListener("DOMContentLoaded", () => {
    const captureExpandedState = () => {
        const openDetails = Array.from(document.querySelectorAll("details[open]"));
        const expandedNodes = Array.from(document.querySelectorAll('[aria-expanded="true"]'));

        return { openDetails, expandedNodes };
    };

    const restoreExpandedState = (state) => {
        state.openDetails.forEach((node) => {
            if (document.contains(node)) {
                node.setAttribute("open", "open");
            }
        });

        state.expandedNodes.forEach((node) => {
            if (document.contains(node)) {
                node.setAttribute("aria-expanded", "true");
            }
        });
    };

    const isActionButton = (button, action) => {
        const text = (button.textContent || "").trim().toLowerCase();
        return text === action || button.dataset.action === action;
    };

    const findSiblingEditButton = (button) => {
        const container = button.closest(".task-actions, .actions, .menu-actions") || button.parentElement;
        if (!container) {
            return null;
        }

        return Array.from(container.querySelectorAll("button")).find((candidate) => {
            const text = (candidate.textContent || "").trim().toLowerCase();
            return text === "edit" || candidate.dataset.action === "edit";
        }) || null;
    };

    document.body.addEventListener("click", (event) => {
        const button = event.target.closest("button");
        if (!button) {
            return;
        }

        const isUndoOrDone = isActionButton(button, "undo") || isActionButton(button, "done");
        if (!isUndoOrDone) {
            return;
        }

        const previousState = captureExpandedState();
        const editButton = findSiblingEditButton(button);

        requestAnimationFrame(() => {
            restoreExpandedState(previousState);

            if (editButton && document.contains(editButton)) {
                button.insertAdjacentElement("afterend", editButton);
                editButton.style.backgroundColor = "#008000";
                editButton.style.color = "#ffffff";
            }
        });
    });
});
</script>
</body>
</html>
