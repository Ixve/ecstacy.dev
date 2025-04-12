<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';

$userId = $_SESSION['user_id'];
// Load user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch();

// Get generated invite codes
$inviteStmt = $pdo->prepare("SELECT invite_codes.code, invite_codes.used_by, invite_codes.generated_by, users.username as used_by_username
                             FROM invite_codes
                             LEFT JOIN users ON invite_codes.used_by = users.id
                             WHERE invite_codes.generated_by = :user_id");
$inviteStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$inviteStmt->execute();
$invites = $inviteStmt->fetchAll();

$discordConnected = !empty($user['discord']);

// ---------- Forum Backend Logic ----------

// Create new post (from list view)
if (isset($_POST['submit_post'])) {
    $category = $_POST['category'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $imagePath = null;
    $attachmentPath = null;
    
    // Image upload if available
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === UPLOAD_ERR_OK) {
        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (in_array($_FILES['post_image']['type'], $allowedImageTypes)) {
            $uploadDir = 'uploads/forum/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['post_image']['name']);
            $targetFile = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['post_image']['tmp_name'], $targetFile)) {
                $imagePath = $targetFile;
            }
        }
    }
    // Attachment upload (e.g. PDF, DOC, DOCX, TXT, ZIP)
    if (isset($_FILES['post_attachment']) && $_FILES['post_attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedAttachmentTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'application/zip'];
        if (in_array($_FILES['post_attachment']['type'], $allowedAttachmentTypes)) {
            $uploadDir = 'uploads/forum/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = "att_" . time() . '_' . basename($_FILES['post_attachment']['name']);
            $targetFile = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['post_attachment']['tmp_name'], $targetFile)) {
                $attachmentPath = $targetFile;
            }
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO forum_posts (user_id, category, title, content, image, attachment, created_at) VALUES (:user_id, :category, :title, :content, :image, :attachment, NOW())");
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':category', $category, PDO::PARAM_STR);
    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
    $stmt->bindParam(':image', $imagePath, PDO::PARAM_STR);
    $stmt->bindParam(':attachment', $attachmentPath, PDO::PARAM_STR);
    $stmt->execute();
    header("Location: user_dashboard.php?forum_category=" . urlencode($category));
    exit();
}

// Create new reply (from detail view)
if (isset($_POST['submit_reply'])) {
    $postId = $_POST['post_id'];
    $replyContent = trim($_POST['reply_content']);
    $stmt = $pdo->prepare("INSERT INTO forum_replies (post_id, user_id, content, created_at) VALUES (:post_id, :user_id, :content, NOW())");
    $stmt->bindParam(':post_id', $postId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':content', $replyContent, PDO::PARAM_STR);
    $stmt->execute();
    header("Location: user_dashboard.php?forum_post=" . urlencode($postId));
    exit();
}

// Process post edit (only for the owner)
if (isset($_POST['submit_edit'])) {
    $postId = $_POST['post_id'];
    $title = trim($_POST['edit_title']);
    $content = trim($_POST['edit_content']);
    $imagePath = null;
    $attachmentPath = null;

    // Optional new image upload
    if (isset($_FILES['edit_post_image']) && $_FILES['edit_post_image']['error'] === UPLOAD_ERR_OK) {
        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (in_array($_FILES['edit_post_image']['type'], $allowedImageTypes)) {
            $uploadDir = 'uploads/forum/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['edit_post_image']['name']);
            $targetFile = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['edit_post_image']['tmp_name'], $targetFile)) {
                $imagePath = $targetFile;
            }
        }
    }
    // Optional new attachment upload
    if (isset($_FILES['edit_post_attachment']) && $_FILES['edit_post_attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedAttachmentTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'application/zip'];
        if (in_array($_FILES['edit_post_attachment']['type'], $allowedAttachmentTypes)) {
            $uploadDir = 'uploads/forum/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = "att_" . time() . '_' . basename($_FILES['edit_post_attachment']['name']);
            $targetFile = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['edit_post_attachment']['tmp_name'], $targetFile)) {
                $attachmentPath = $targetFile;
            }
        }
    }
    // If no new image or attachment uploaded, retain old ones
    if (empty($imagePath) || empty($attachmentPath)) {
        $stmt = $pdo->prepare("SELECT image, attachment FROM forum_posts WHERE id = :id");
        $stmt->bindParam(':id', $postId, PDO::PARAM_INT);
        $stmt->execute();
        $old = $stmt->fetch();
        if (empty($imagePath)) {
            $imagePath = $old['image'];
        }
        if (empty($attachmentPath)) {
            $attachmentPath = $old['attachment'];
        }
    }
    $stmt = $pdo->prepare("UPDATE forum_posts SET title = :title, content = :content, image = :image, attachment = :attachment WHERE id = :id AND user_id = :user_id");
    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
    $stmt->bindParam(':image', $imagePath, PDO::PARAM_STR);
    $stmt->bindParam(':attachment', $attachmentPath, PDO::PARAM_STR);
    $stmt->bindParam(':id', $postId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    header("Location: user_dashboard.php?forum_post=" . urlencode($postId));
    exit();
}

// Determine active section. If any forum parameter is present, set forum as active; otherwise, account.
$activeSection = "account";
if (isset($_GET['forum_category']) || isset($_GET['forum_post'])) {
    $activeSection = "forum";
}
// For list view, capture selected category (default: "Configs")
$selectedCategory = isset($_GET['forum_category']) ? $_GET['forum_category'] : 'Configs';
// Check if a specific post detail is requested
$detailPostId = isset($_GET['forum_post']) ? intval($_GET['forum_post']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Dashboard</title>
<!-- Original Styles -->
<style>
    .message-box {
        padding: 15px;
        margin: 20px 0;
        border-radius: 5px;
        width: 100%;
        text-align: center;
    }
    .message-box.success {
        background: #4CAF50;
        color: white;
    }
    .message-box.error {
        background: #f44336;
        color: white;
    }
    body {
        background: #121212;
        color: #fff;
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .container {
        width: 90%;
        max-width: 1000px;
        margin-top: 50px;
    }
    .navbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #222;
        padding: 15px;
        border-radius: 10px;
        box-shadow: 0 0 10px #da70d6;
        width: 97%;
        position: relative;
    }
    .logo {
        font-size: 20px;
        font-weight: bold;
        color: #da70d6;
        padding-right: 20px;
        border-right: 2px solid #da70d6;
    }
    .nav-links {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-grow: 1;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }
    .nav-links a {
        color: #da70d6;
        text-decoration: none;
        font-weight: bold;
        padding: 10px 15px;
        transition: 0.3s;
    }
    .nav-links a:hover {
        background: #c060c0;
        border-radius: 5px;
    }
    .content {
        width: 100%;
        margin-top: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .section {
        display: none;
        width: 100%;
        padding: 20px;
        flex-direction: column;
        align-items: center;
    }
    .section.active {
        display: flex;
    }
    .card {
        background: #1e1e1e;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px #da70d6;
        margin: 10px 0;
        width: 50%;
        text-align: center;
    }
    /* Speziell fÃ¼r Account */
    .account-card {
        padding: 30px;
    }
    .account-header {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .profile-pic-container {
        position: relative;
        cursor: pointer;
    }
    .profile-pic-container img {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        border: 2px solid #da70d6;
        object-fit: cover;
    }
    .change-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.5);
        color: #fff;
        text-align: center;
        font-size: 14px;
        padding: 5px;
        display: none;
        border-bottom-left-radius: 50%;
        border-bottom-right-radius: 50%;
    }
    .account-details h2 {
        margin: 0;
        font-size: 24px;
        color: #da70d6;
    }
    .account-details p {
        margin: 5px 0;
        font-size: 16px;
    }
    /* Styling fÃ¼r den ID-Link */
    .id-link {
        color: #da70d6;
        text-shadow: 0 0 10px #da70d6;
        text-decoration: none;
        font-weight: bold;
    }
    .id-link:hover {
        opacity: 0.8;
    }
    .account-bio {
        margin-top: 20px;
        text-align: left;
    }
    .account-bio h3 {
        margin-bottom: 10px;
        font-size: 20px;
        color: #da70d6;
    }
    textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #444;
        border-radius: 5px;
        background: #1e1e1e;
        color: #fff;
    }
    button {
        background: #da70d6;
        border: none;
        padding: 10px 20px;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
        border-radius: 5px;
        transition: 0.3s;
        margin-top: 10px;
    }
    button:hover {
        background: #c060c0;
    }
    input[type="file"] {
        display: none;
    }
    input, textarea {
        width: 100%;
        margin-top: 10px;
    }
    /* Tabellen Styling */
    table {
        width: 100%;
        margin-top: 20px;
        border-collapse: collapse;
    }
    th, td {
        padding: 10px;
        text-align: left;
        border: 1px solid #444;
    }
    th {
        background: #222;
    }
    td {
        background: #1e1e1e;
    }
    td:last-child {
        text-align: right;
    }
    /* Styling fÃ¼r den Discord-Button */
    .discord-btn {
        background: #5865F2;
        border: none;
        padding: 10px 20px;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
        border-radius: 5px;
        transition: 0.3s;
        margin-top: 10px;
        text-decoration: none;
        display: inline-block;
    }
    .discord-btn:hover {
        background: #4752c4;
    }
    /* Styling fÃ¼r das volle Popup, das erscheint, wenn Discord nicht verbunden ist */
    .discord-popup-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .discord-popup {
        background: #222;
        padding: 30px;
        border-radius: 10px;
        text-align: center;
        box-shadow: 0 0 20px #da70d6;
        max-width: 400px;
        width: 90%;
    }
    .discord-popup h2 {
        margin-bottom: 20px;
        color: #da70d6;
    }
    .discord-popup p {
        margin-bottom: 20px;
    }
    /* Additional style for forum preview glowing container */
    .post-list-item {
        border: 1px solid #da70d6;
        border-radius: 6px;
        padding: 20px;
        margin-bottom: 15px;
        background: #1e1e1e;
        box-shadow: 0 0 8px 2px rgba(218,112,214,0.7);
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .post-list-item:hover {
        transform: scale(1.02);
        box-shadow: 0 0 12px 3px rgba(218,112,214,1);
    }
    .post-list-title {
        font-size: 20px;
        font-weight: bold;
        color: #da70d6;
        margin-bottom: 5px;
    }
</style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <div class="logo">Ecstacy</div>
        <div class="nav-links">
            <a href="#" onclick="switchSection('account')">Account</a>
            <a href="#" onclick="switchSection('download')">Launcher</a>
            <a href="#" onclick="switchSection('settings')">Settings</a>
            <a href="#" onclick="switchSection('redeem')">Redeem</a>
            <a href="#" onclick="switchSection('invites')">Invites</a>
            <a href="user_dashboard.php?forum_category=<?php echo urlencode($selectedCategory); ?>">Forum</a>
        </div>
    </div>
    
    <div class="content">
        <!-- Account Section -->
        <div id="account" class="section <?php echo ($activeSection === "account") ? "active" : ""; ?>">
            <h2>Account Information</h2>
            <?php if (isset($_SESSION['profile_message'])): ?>
                <div class="message-box <?php echo $_SESSION['profile_message']['type']; ?>">
                    <?php echo $_SESSION['profile_message']['text']; ?>
                </div>
                <?php unset($_SESSION['profile_message']); ?>
            <?php endif; ?>
            <div class="card account-card">
                <div class="account-header">
                    <div class="profile-pic-container">
                        <img id="profilePic" src="<?php echo htmlspecialchars('/..' . $user['profile_pic']); ?>" alt="Profile Picture">
                        <div class="change-overlay">Change</div>
                    </div>
                    <div class="account-details">
                        <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                        <p>
                            <strong>ID:</strong>
                            <a class="id-link" href="/id/<?php echo htmlspecialchars($user['id']); ?>">
                                <?php echo htmlspecialchars($user['id']); ?>
                            </a>
                        </p>
                        <p><strong>Created:</strong> <?php echo htmlspecialchars($user['reg_date']); ?></p>
                        <p>
                            <?php if ($discordConnected): ?>
                                <strong>Discord:</strong> <?php echo htmlspecialchars($user['discord']); ?>
                            <?php else: ?>
                                <a href="discord.php" class="discord-btn">Connect Discord</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <form action="update_profile.php" method="post" enctype="multipart/form-data" class="profile-form">
                <div>
                    <label for="mp3">Upload MP3 (max. 10MB)</label>
                    <input type="file" id="mp3" name="mp3" accept="audio/mpeg">
                </div>
                <div class="account-bio">
                    <h3>Bio</h3>
                    <textarea name="bio" id="bio" rows="4"><?php echo htmlspecialchars($user['bio']); ?></textarea>
                </div>
                <?php if (isset($_SESSION['profile_update_error'])): ?>
                    <div class="message-box error">
                        <?php echo $_SESSION['profile_update_error']; unset($_SESSION['profile_update_error']); ?>
                    </div>
                <?php endif; ?>
                <input type="file" id="profile_pic_input" name="profile_pic" accept="image/png, image/gif" hidden>
                <button type="submit" class="update-button">Update Profile</button>
            </form>
        </div>
        
        <!-- Launcher Section -->
        <div id="download" class="section">
            <h2>Download Launcher</h2>
            <div class="card">
                <?php if ($user['sub_status'] === 'active') { ?>
                    <a href="launcher.zip" download><button>Download</button></a>
                <?php } else { ?>
                    <p>You need an active subscription to download.</p>
                <?php } ?>
            </div>
        </div>
        
        <!-- Settings Section -->
        <div id="settings" class="section">
            <h2>Account Settings</h2>
            <div class="card">
                <form action="change_username.php" method="post">
                    <input type="text" name="new_username" placeholder="New Username" required>
                    <button type="submit">Change Username</button>
                </form>
                <hr>
                <form action="change_password.php" method="post">
                    <input type="password" name="current_password" placeholder="Current Password" required>
                    <input type="password" name="new_password" placeholder="New Password" required>
                    <button type="submit">Change Password</button>
                </form>
            </div>
        </div>
        
        <!-- Redeem Section -->
        <div id="redeem" class="section">
            <h2>Redeem Code</h2>
            <div class="card">
                <form action="redeem_license.php" method="post">
                    <input type="text" name="license_code" placeholder="Enter Code" required>
                    <button type="submit">Redeem</button>
                </form>
            </div>
        </div>
        
        <!-- Invites Section -->
        <div id="invites" class="section">
            <h2>Invites</h2>
            <div class="card">
                <p><strong>Invites Left:</strong> <?php echo htmlspecialchars($user['invites_left']); ?></p>
                <?php if ($user['invites_left'] > 0): ?>
                    <form action="generate_invite.php" method="post">
                        <button type="submit">Generate Invite</button>
                    </form>
                <?php else: ?>
                    <p>You have no invites left.</p>
                <?php endif; ?>
            </div>
            <h3>Generated Invites</h3>
            <div class="card">
                <?php if (count($invites) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Invite Code</th>
                                <th>Used By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invites as $invite): ?>
                                <tr>
                                    <td>
                                        <a class="id-link" href="https://ecstacy.dev/register/?invite=<?php echo urlencode($invite['code']); ?>" target="_blank" style="color: #ff00ff; text-decoration: none;">
                                            <?php echo htmlspecialchars($invite['code']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($invite['used_by']): ?>
                                            <a class="id-link" href="/id/<?php echo htmlspecialchars($invite['used_by']); ?>">
                                                <?php echo htmlspecialchars($invite['used_by_username']); ?>
                                            </a>
                                        <?php else: ?>
                                            Not Used
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No invites generated yet.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Forum Section -->
        <div id="forum" class="section <?php echo ($activeSection === "forum") ? "active" : ""; ?>">
            <?php
            // If a specific post is requested, show detail view; otherwise, list view.
            if ($detailPostId > 0) {
                $stmt = $pdo->prepare("SELECT forum_posts.*, users.username, users.profile_pic FROM forum_posts JOIN users ON forum_posts.user_id = users.id WHERE forum_posts.id = :id");
                $stmt->bindParam(':id', $detailPostId, PDO::PARAM_INT);
                $stmt->execute();
                $post = $stmt->fetch();
                if (!$post) {
                    echo '<p>Post not found.</p>';
                } else {
                    ?>
                    <a href="user_dashboard.php?forum_category=<?php echo urlencode($post['category']); ?>" class="back-link">&larr; Back to List</a>
                    <div class="card post-detail-card">
                        <div class="post-detail-header">
                            <a href="/id/<?php echo htmlspecialchars($post['user_id']); ?>">
                                <img src="<?php echo htmlspecialchars('/..' . $post['profile_pic']); ?>" alt="Profile Picture">
                            </a>
                            <div class="post-info">
                                <a class="id-link" href="/id/<?php echo htmlspecialchars($post['user_id']); ?>">
                                    <?php echo htmlspecialchars($post['username']); ?>
                                </a>
                                <div class="post-detail-date"><?php echo htmlspecialchars($post['created_at']); ?></div>
                            </div>
                            <?php if ($post['user_id'] == $userId): ?>
                                <button class="edit-button">Edit</button>
                            <?php endif; ?>
                        </div>
                        <h2><?php echo htmlspecialchars($post['title']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                        <?php if (!empty($post['image'])): ?>
                            <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="Post Image" class="post-image">
                        <?php endif; ?>
                        <?php if (!empty($post['attachment'])): ?>
                            <a class="attachment-link" href="<?php echo htmlspecialchars($post['attachment']); ?>" download>Download Attachment</a>
                        <?php endif; ?>
                        <?php if ($post['user_id'] == $userId): ?>
                        <div class="edit-form">
                            <form action="user_dashboard.php?forum_post=<?php echo urlencode($post['id']); ?>" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="post_id" value="<?php echo htmlspecialchars($post['id']); ?>">
                                <input type="text" name="edit_title" placeholder="Title" value="<?php echo htmlspecialchars($post['title']); ?>" required>
                                <textarea name="edit_content" rows="4" placeholder="Content" required><?php echo htmlspecialchars($post['content']); ?></textarea>
                                <label>Upload new image (optional):</label>
                                <input type="file" name="edit_post_image" accept="image/*">
                                <label>Upload new attachment (optional):</label>
                                <input type="file" name="edit_post_attachment">
                                <button type="submit" name="submit_edit">Save</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Replies -->
                    <?php
                    $replyStmt = $pdo->prepare("SELECT forum_replies.*, users.username, users.profile_pic FROM forum_replies JOIN users ON forum_replies.user_id = users.id WHERE forum_replies.post_id = :post_id ORDER BY created_at ASC");
                    $replyStmt->bindParam(':post_id', $post['id'], PDO::PARAM_INT);
                    $replyStmt->execute();
                    $replies = $replyStmt->fetchAll();
                    if ($replies):
                        foreach ($replies as $reply):
                    ?>
                        <div class="card forum-reply">
                            <div class="reply-header">
                                <a href="/id/<?php echo htmlspecialchars($reply['user_id']); ?>">
                                    <img src="<?php echo htmlspecialchars('/..' . $reply['profile_pic']); ?>" alt="Profile Picture">
                                </a>
                                <a class="id-link" href="/id/<?php echo htmlspecialchars($reply['user_id']); ?>">
                                    <?php echo htmlspecialchars($reply['username']); ?>
                                </a>
                                <span class="reply-date"><?php echo htmlspecialchars($reply['created_at']); ?></span>
                            </div>
                            <p><?php echo nl2br(htmlspecialchars($reply['content'])); ?></p>
                        </div>
                    <?php
                        endforeach;
                    endif;
                    ?>
                    <!-- Reply Form -->
                    <div class="card reply-box">
                        <form action="user_dashboard.php?forum_post=<?php echo urlencode($post['id']); ?>" method="post">
                            <input type="hidden" name="post_id" value="<?php echo htmlspecialchars($post['id']); ?>">
                            <textarea name="reply_content" rows="3" placeholder="Your reply" required></textarea>
                            <button type="submit" name="submit_reply">Reply</button>
                        </form>
                    </div>
                    <?php
                }
            } else {
                // List view: Forum Overview of posts in the selected category
                ?>
                <h2>Forum Overview â€“ Category: <?php echo htmlspecialchars($selectedCategory); ?></h2>
                <!-- Category Navigation -->
                <div class="forum-categories">
                    <?php 
                    $categories = ['Configs', 'suggestions', 'General'];
                    foreach ($categories as $cat):
                        $activeClass = ($cat === $selectedCategory) ? 'active' : '';
                    ?>
                    <a href="user_dashboard.php?forum_category=<?php echo urlencode($cat); ?>" class="<?php echo $activeClass; ?>"><?php echo htmlspecialchars($cat); ?></a>
                    <?php endforeach; ?>
                </div>
                <button id="toggle-post-form">Create New Post</button>
                <div id="new-post-form" style="display:none;">
                    <div class="card new-post-card">
                        <h3>Create New Post</h3>
                        <form action="user_dashboard.php?forum_category=<?php echo urlencode($selectedCategory); ?>" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedCategory); ?>">
                            <input type="text" name="title" placeholder="Title" required>
                            <textarea name="content" rows="3" placeholder="Your post content" required></textarea>
                            <label>Upload image (optional):</label>
                            <input type="file" name="post_image" accept="image/*">
                            <label>Attach file (optional):</label>
                            <input type="file" name="post_attachment">
                            <button type="submit" name="submit_post">Post</button>
                        </form>
                    </div>
                </div>
                <?php
                // Display list of posts (only title, date, and author) with glowing container
                $stmt = $pdo->prepare("SELECT forum_posts.id, forum_posts.title, forum_posts.created_at, users.username, users.profile_pic 
                                       FROM forum_posts 
                                       JOIN users ON forum_posts.user_id = users.id 
                                       WHERE forum_posts.category = :category 
                                       ORDER BY created_at DESC");
                $stmt->bindParam(':category', $selectedCategory, PDO::PARAM_STR);
                $stmt->execute();
                $posts = $stmt->fetchAll();
                if ($posts):
                    echo '<div id="forum-list" class="forum-list">';
                    foreach ($posts as $post):
                    ?>
                    <a class="post-list-item" href="user_dashboard.php?forum_post=<?php echo urlencode($post['id']); ?>">
                        <div class="post-list-title"><?php echo htmlspecialchars($post['title']); ?></div>
                        <small><?php echo htmlspecialchars($post['created_at']); ?> &ndash; By: <?php echo htmlspecialchars($post['username']); ?></small>
                    </a>
                    <?php
                    endforeach;
                    echo '</div>';
                else:
                    echo '<p>No posts in this category.</p>';
                endif;
            }
            ?>
        </div>
        
    </div>
</div>

<?php if (!$discordConnected): ?>
<div class="discord-popup-overlay">
    <div class="discord-popup">
        <h2>Connect Discord</h2>
        <p>Please connect your Discord to continue.</p>
        <a href="discord.php" class="discord-btn">Connect Discord</a>
    </div>
</div>
<?php endif; ?>

<script>
// Inline JavaScript
document.addEventListener("DOMContentLoaded", function() {
    // Profile picture events
    const picContainer = document.querySelector('.profile-pic-container');
    if (picContainer) {
        const overlay = picContainer.querySelector('.change-overlay');
        picContainer.addEventListener('mouseover', function() {
            overlay.style.display = 'block';
        });
        picContainer.addEventListener('mouseout', function() {
            overlay.style.display = 'none';
        });
        picContainer.addEventListener('click', function() {
            document.getElementById('profile_pic_input').click();
        });
        const profilePicInput = document.getElementById('profile_pic_input');
        profilePicInput.addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePic').src = e.target.result;
                }
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    }
    // Toggle Edit Form in detail view
    document.querySelectorAll('.edit-button').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const editForm = this.closest('.card').querySelector('.edit-form');
            if (editForm.style.display === 'none' || editForm.style.display === '') {
                editForm.style.display = 'block';
            } else {
                editForm.style.display = 'none';
            }
        });
    });
    // Toggle New Post Form in forum list view
    const toggleBtn = document.getElementById('toggle-post-form');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            const postForm = document.getElementById('new-post-form');
            if (postForm.style.display === 'none' || postForm.style.display === '') {
                postForm.style.display = 'block';
            } else {
                postForm.style.display = 'none';
            }
        });
    }
});
// For switching sections with fade animations
function switchSection(sectionId) {
    document.querySelectorAll('.section').forEach(sec => {
        sec.classList.remove('active');
    });
    document.getElementById(sectionId).classList.add('active');
}
</script>
</body>
</html>
