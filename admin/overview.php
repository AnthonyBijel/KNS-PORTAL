   <?php
    require "../db.php";

    $stmt = $pdo->query("
                SELECT *
                FROM announcements
                ORDER BY created_at DESC
            ");

    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>





   <div class="greeting-contianer">
       <div class="welcome-text-box">
           <p class="date-layer">Wednesday, September 2</p>
           <h1 class="greet-layer">Good afternoon, Anton.</h1>
           <p class="request-review">Manage announcement, update information, and keep the school community informed.</p>
       </div>
   </div>
   <!--TOTAL OF ALL THE INFORMATION IN PORTAL-->
   <div class="total-info-container">
       <div class="total-announcement">
           <img src="..//asset/logo/megaphone.gif">
           <div class="total-info-text">
               <h1>12</h1>
               <p>Total Announcement</p>
           </div>

       </div>
       <div class="total-students">
           <img src="..//asset/logo/discussion.gif">
           <div class="total-info-text">
               <h1>1,200</h1>
               <p>Total Students</p>
           </div>
       </div>
       <div class="total-modules">
           <img src="..//asset/logo/folder.gif">
           <div class="total-info-text">
               <h1>25</h1>
               <p>Modules Uploads</p>
           </div>
       </div>
   </div>

   <!--ANNNOUNCEMENT SECTION-->

   <!--ANNOUNCEMENT-CONTENT-SECTION-->
   <div class="announcement-content">
       <div class="post-create-container">
           <div class="create-upper">
               <img src="..//asset/logo/loud-speaker.png">
               <h1>Create Announcement</h1>
           </div>

           <form action="create_post.php" method="POST" enctype="multipart/form-data">

               <div class="create-content-box">
                   <div class="create-announcement-type">
                       <p>announcement type</p>
                       <select name="announcement_type" required>
                           <option value="">Select type</option>
                           <option value="Academic">Academic</option>
                           <option value="Events">Events</option>
                           <option value="Guidelines">Guidelines</option>
                       </select>
                   </div>
                   <div class="create-discription">
                       <p>Description</p>
                       <textarea
                           name="description"
                           placeholder="Write your announcement here..."
                           required></textarea>
                   </div>
                   <div class="create-media-upload">
                       <p>Media &#40Image or Video&#41</p>
                       <div class="media-upload">
                           <label for="mediaInput" class="upload-box">
                               <p>Add photo or video</p>
                           </label>
                           <input
                               type="file"
                               id="mediaInput"
                               name="media"
                               accept="image/*,video/*"
                               hidden>
                           <div id="mediaPreview"></div>

                       </div>

                   </div>
                   <div class="post-button">
                       <button type="submit">
                           <img src="..//asset/logo/paper-plane.png">
                           <p>Post Announcement</p>
                       </button>
                   </div>
               </div>

           </form>

       </div>

       <div class="announcement-feed">
           <?php foreach ($announcements as $post): ?>
               <div class="post-content">
                   <div class="post-user-name">
                       <div class="post-logo">
                           <img class="post-profile" src="../asset/logo/273844945_520328949759168_1274024641438937791_n.jpg" alt="Kolehiyo ng Subic">
                       </div>
                       <div class="post-name">
                           <h1>kolehiyo ng Subic</h1>
                           <p><?= date('F j, Y g:i A', strtotime($post['created_at'])) ?></p>
                       </div>
                   </div>
                   <div class="description-box">
                       <p class="description-text"><?= htmlspecialchars($post['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                       <button type="button" class="see-more-btn">See more</button>
                   </div>

                   <?php if (!empty($post['media_path'])): ?>
                       <?php if ($post['media_type'] === 'image'): ?>
                           <div class="post-image">
                               <img src="../<?= htmlspecialchars($post['media_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Announcement image">
                           </div>
                       <?php elseif ($post['media_type'] === 'video'): ?>
                           <div class="post-image">
                               <video controls preload="metadata">
                                   <source src="../<?= htmlspecialchars($post['media_path'], ENT_QUOTES, 'UTF-8') ?>">
                               </video>
                           </div>
                       <?php endif; ?>
                   <?php endif; ?>

                   <div class="share-button">
                       <img src="../asset/logo/share-square.png" alt="">
                       <p>SHARE</p>
                   </div>
               </div>
           <?php endforeach; ?>
       </div>
   </div>
