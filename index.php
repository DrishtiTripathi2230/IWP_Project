<?php
// Report all errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --------------------------------------------------
// Database Connection Configuration
// --------------------------------------------------

$host = 'localhost';
$user = 'root'; // Default XAMPP user
$pass = '';     // Default XAMPP password (often blank)
$db = 'lcebb';  // Database name

// Connect to the database
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    // If connection fails, display a clean error message
    die("<div style='background-color:#fee2e2; padding: 20px; border: 1px solid #ef4444; color: #991b1b;'>
         <strong>Database Connection Failed:</strong> Ensure MySQL is running and the 'lcebb' database exists. Error: " . htmlspecialchars($conn->connect_error) . "
         </div>");
}

// --------------------------------------------------
// USER SIMULATION & Initial Setup
// --------------------------------------------------

// Simulated creator ID for Edit/Delete checks
$current_user_id = 'community_admin';

$message = ''; // For success/error messages
$edit_mode = false;
$event_to_edit = [];
// Capture filter category from URL parameter, defaulting to 'all'
$filter_category = isset($_GET['filter']) ? $conn->real_escape_string($_GET['filter']) : 'all';

// --------------------------------------------------
// ACTION HANDLING: DELETE
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    $check_creator_sql = "SELECT created_by FROM events WHERE id = $id";
    $result = $conn->query($check_creator_sql);

    if ($result && $result->num_rows > 0) {
        $event = $result->fetch_assoc();
        if ($event['created_by'] === $current_user_id) {
            $delete_sql = "DELETE FROM events WHERE id = $id";
            if ($conn->query($delete_sql) === TRUE) {
                $message = "Event successfully deleted.";
            } else {
                $message = "Error deleting event: " . $conn->error;
            }
        } else {
            $message = "Error: You can only delete events you created.";
        }
    } else {
        $message = "Error: Event not found for deletion.";
    }
}

// --------------------------------------------------
// ACTION HANDLING: EDIT MODE (Populate form)
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $edit_mode = true;

    $select_sql = "SELECT * FROM events WHERE id = $id";
    $result = $conn->query($select_sql);

    if ($result && $result->num_rows == 1) {
        $event_to_edit = $result->fetch_assoc();

        if ($event_to_edit['created_by'] !== $current_user_id) {
            $message = "Error: You can only edit events you created.";
            $edit_mode = false; // Disable edit mode if not the creator
        }
    } else {
        $message = "Error: Event not found.";
        $edit_mode = false;
    }
}

// --------------------------------------------------
// ACTION HANDLING: SUBMIT (Create/Update)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and capture all fields, mapping 'datetime' input name
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $category = $conn->real_escape_string($_POST['category']);
    $event_date = $conn->real_escape_string($_POST['datetime']); // Mapped 'datetime' input to PHP variable
    $location = $conn->real_escape_string($_POST['location']);

    // Check if it's an UPDATE operation
    if (isset($_POST['event_id']) && !empty($_POST['event_id'])) {
        $id = intval($_POST['event_id']);

        // Final creator safety check for update
        $check_creator_sql = "SELECT created_by FROM events WHERE id = $id";
        $result = $conn->query($check_creator_sql);
        $event = $result->fetch_assoc();

        if ($event['created_by'] === $current_user_id) {
            $update_sql = "UPDATE events SET 
                            title='$title', 
                            description='$description',
                            category='$category',
                            location='$location',
                            event_date='$event_date'
                          WHERE id = $id";

            if ($conn->query($update_sql) === TRUE) {
                $message = "Event successfully updated!";
            } else {
                $message = "Error updating event: " . $conn->error;
            }
        } else {
            $message = "Error: Cannot update an event you did not create.";
        }
    } else {
        // CREATE operation
        $insert_sql = "INSERT INTO events (title, description, category, location, event_date, created_by) VALUES 
                       ('$title', '$description', '$category', '$location', '$event_date', '$current_user_id')";

        if ($conn->query($insert_sql) === TRUE) {
            $message = "New event posted successfully!";
        } else {
            $message = "Error posting event: " . $conn->error;
        }
    }
}

// --------------------------------------------------
// DATA RETRIEVAL: CURRENT EVENTS & ARCHIVE
// --------------------------------------------------
$base_query = "SELECT id, title, description, category, location, event_date, created_by FROM events";

// 1. Current Events Query (event_date >= today)
$current_events_where = ["event_date >= NOW()"];

if ($filter_category !== 'all') {
    $current_events_where[] = "category = '$filter_category'";
}

$current_events_query = $base_query . " WHERE " . implode(" AND ", $current_events_where) . " ORDER BY event_date ASC";
$current_events_result = $conn->query($current_events_query);

// 2. Archived Events Query (event_date < today)
$archive_query = $base_query . " WHERE event_date < NOW() ORDER BY event_date DESC";
$archive_result = $conn->query($archive_query);

// 3. Categories for Filter List
$categories_query = "SELECT DISTINCT category FROM events ORDER BY category ASC";
$categories_result = $conn->query($categories_query);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Community Event Bulletin Board (LCEBB)</title>
    <!-- Load Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Load Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Link to our custom styles (for font and scrollbar) -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="bg-gray-100 min-h-screen">

    <div id="app" class="flex flex-col min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-md">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-3xl font-bold text-indigo-700">LCEBB</h1>
                <div id="auth-status" class="flex items-center space-x-4">
                    <!-- User ID Display (PHP) -->
                    <span id="user-id-display" class="text-sm text-gray-600 truncate max-w-[150px]">
                        User: <strong class="text-indigo-600"><?php echo htmlspecialchars($current_user_id); ?></strong>
                    </span>
                    <!-- Submit button to scroll to form -->
                    <a href="#event-form-section"
                        class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300">
                        <i data-lucide="plus" class="w-5 h-5 inline-block mr-1"></i> Submit Event
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8">

            <!-- Message Box (PHP Alert) -->
            <?php if (!empty($message)):
                // Determine alert class using simple string check
                $alert_class = strpos($message, 'Error') !== false ? 'alert-error' : 'alert-success';
                ?>
                <div class="<?php echo $alert_class; ?>" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row gap-6">

                <!-- LEFT SIDEBAR: Event Form and Filter -->
                <div class="w-full md:w-1/3 space-y-6 flex-shrink-0">

                    <!-- Event Submission/Edit Form -->
                    <div id="event-form-section" class="bg-white rounded-xl shadow-lg p-6">
                        <h2 class="text-2xl font-bold mb-6 text-indigo-700 border-b pb-2">
                            <?php echo $edit_mode ? 'Edit Event' : 'Post a New Event'; ?>
                        </h2>

                        <!-- The form's action now includes the edit ID if in edit mode -->
                        <form method="POST"
                            action="index.php<?php echo $edit_mode ? "?action=edit&id=" . $event_to_edit['id'] : ""; ?>"
                            class="space-y-4">
                            <!-- Hidden field for ID (required for UPDATE) -->
                            <?php if ($edit_mode): ?>
                                <input type="hidden" name="event_id"
                                    value="<?php echo htmlspecialchars($event_to_edit['id']); ?>">
                            <?php endif; ?>

                            <!-- Title -->
                            <div>
                                <label for="event-title"
                                    class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                <input type="text" id="event-title" name="title" required maxlength="100"
                                    value="<?php echo $edit_mode ? htmlspecialchars($event_to_edit['title']) : ''; ?>"
                                    placeholder="e.g., Annual Neighborhood Potluck"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="event-description"
                                    class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea id="event-description" name="description" required rows="4" maxlength="500"
                                    placeholder="Details about the event..."
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"><?php echo $edit_mode ? htmlspecialchars($event_to_edit['description']) : ''; ?></textarea>
                            </div>

                            <!-- Event Date/Time -->
                            <div>
                                <label for="event-datetime" class="block text-sm font-medium text-gray-700 mb-1">Event
                                    Date/Time</label>
                                <?php
                                $datetime_value = '';
                                if ($edit_mode) {
                                    // Format the MySQL DATETIME field for the HTML datetime-local input
                                    $mysql_datetime = $event_to_edit['event_date'];
                                    $datetime_value = date('Y-m-d\TH:i', strtotime($mysql_datetime));
                                }
                                ?>
                                <input type="datetime-local" id="event-datetime" name="datetime" required
                                    value="<?php echo $datetime_value; ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Category & Location -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="event-category"
                                        class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                    <select id="event-category" name="category" required
                                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="" disabled <?php if (!$edit_mode)
                                            echo 'selected'; ?>>Select
                                            Category</option>
                                        <?php
                                        $categories_static = ['Meeting', 'Sale', 'Notice', 'Workshop'];
                                        $current_cat = $edit_mode ? $event_to_edit['category'] : '';
                                        foreach ($categories_static as $cat) {
                                            $selected = ($cat == $current_cat) ? 'selected' : '';
                                            echo "<option value=\"" . htmlspecialchars($cat) . "\" $selected>" . htmlspecialchars($cat) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="event-location"
                                        class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                    <input type="text" id="event-location" name="location" required maxlength="100"
                                        value="<?php echo $edit_mode ? htmlspecialchars($event_to_edit['location']) : ''; ?>"
                                        placeholder="e.g., Community Hall"
                                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                            </div>

                            <button type="submit"
                                class="w-full py-3 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition duration-300 mt-6">
                                <?php echo $edit_mode ? 'Update Event' : 'Post Event'; ?>
                            </button>

                            <?php if ($edit_mode): ?>
                                <a href="index.php"
                                    class="w-full block text-center py-2 bg-gray-200 text-gray-700 font-semibold rounded-lg shadow-sm hover:bg-gray-300 transition duration-300 mt-2">
                                    Cancel Edit
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Filter Section (Uses categories retrieved from PHP database query) -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h2 class="text-xl font-bold mb-4 text-gray-700 border-b pb-2">Filter Events</h2>
                        <form method="GET" action="index.php">
                            <div>
                                <label for="category-filter-php"
                                    class="block text-sm font-medium text-gray-700 mb-1">Filter by Category</label>
                                <select id="category-filter-php" name="filter" onchange="this.form.submit()"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                    <option value="all" <?php if ($filter_category === 'all')
                                        echo 'selected'; ?>>All
                                        Categories</option>
                                    <?php
                                    // Populate categories from database result
                                    if ($categories_result && $categories_result->num_rows > 0) {
                                        while ($cat_row = $categories_result->fetch_assoc()) {
                                            $cat_name = htmlspecialchars($cat_row['category']);
                                            $selected = ($cat_name === $filter_category) ? 'selected' : '';
                                            echo "<option value=\"$cat_name\" $selected>$cat_name</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <!-- Status Filter and Sort By from the original HTML are removed as they require client-side JS/data management -->
                        </form>
                    </div>

                </div> <!-- End Sidebar -->

                <!-- RIGHT SIDE: Event List -->
                <div class="w-full md:w-2/3">

                    <h2 class="text-2xl font-bold text-gray-700 mb-4 border-b pb-2">Upcoming Community Events</h2>

                    <div id="event-board" class="event-board grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php if ($current_events_result->num_rows > 0): ?>
                            <?php while ($event = $current_events_result->fetch_assoc()):
                                $is_creator = $event['created_by'] === $current_user_id;
                                $event_datetime = strtotime($event['event_date']);
                                ?>
                                <div
                                    class="bg-white rounded-xl shadow-xl hover:shadow-2xl transition duration-300 p-6 flex flex-col justify-between border-t-4 border-indigo-500">
                                    <div>
                                        <div class="flex justify-between items-start mb-3">
                                            <span
                                                class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-indigo-100 text-indigo-800">
                                                <i data-lucide="calendar-check" class="w-4 h-4 mr-1"></i>
                                                Current Event
                                            </span>
                                            <span
                                                class="text-xs font-semibold text-gray-700 bg-gray-200 px-3 py-1 rounded-full"><?php echo htmlspecialchars($event['category']); ?></span>
                                        </div>
                                        <h3 class="text-xl font-bold text-gray-800 mb-2">
                                            <?php echo htmlspecialchars($event['title']); ?></h3>
                                        <p class="text-sm text-gray-600 mb-4">
                                            <?php echo nl2br(htmlspecialchars($event['description'])); ?></p>
                                    </div>

                                    <div class="mt-4 pt-4 border-t border-gray-100">
                                        <div class="flex items-center text-sm text-gray-600 mb-2">
                                            <i data-lucide="map-pin" class="w-4 h-4 mr-2 flex-shrink-0"></i>
                                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                        <div class="flex items-center text-sm text-gray-600 mb-4">
                                            <i data-lucide="calendar" class="w-4 h-4 mr-2 flex-shrink-0"></i>
                                            <span>
                                                <strong><?php echo date('M j, Y H:i', $event_datetime); ?></strong>
                                            </span>
                                        </div>

                                        <!-- CRUD Operations -->
                                        <?php if ($is_creator): ?>
                                            <div class="flex space-x-2">
                                                <a href="index.php?action=edit&id=<?php echo $event['id']; ?>#event-form-section"
                                                    class="flex-1 py-2 px-3 bg-blue-100 text-blue-700 text-sm rounded-lg hover:bg-blue-200 transition duration-150 text-center">
                                                    <i data-lucide="pencil" class="w-4 h-4 inline mr-1"></i> Edit
                                                </a>
                                                <a href="index.php?action=delete&id=<?php echo $event['id']; ?>"
                                                    class="flex-1 py-2 px-3 bg-red-100 text-red-700 text-sm rounded-lg hover:bg-red-200 transition duration-150 text-center"
                                                    onclick="return confirm('Are you sure you want to delete this event: <?php echo htmlspecialchars($event['title']); ?>?')">
                                                    <i data-lucide="trash-2" class="w-4 h-4 inline mr-1"></i> Delete
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div
                                class="col-span-full p-6 text-center bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 rounded-xl shadow">
                                No upcoming events
                                found<?php echo $filter_category === 'all' ? '.' : " for category: " . htmlspecialchars($filter_category); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Archived Events Section -->
                    <div class="mt-12">
                        <h3 class="text-2xl font-bold text-gray-700 mb-4 border-b pb-2">Recent Past Events (Archive)
                        </h3>
                        <div id="archived-event-board" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <?php if ($archive_result->num_rows > 0): ?>
                                <?php while ($archive_event = $archive_result->fetch_assoc()): ?>
                                    <div
                                        class="bg-white rounded-xl shadow p-6 flex flex-col justify-between border-t-4 border-gray-400 opacity-70">
                                        <div>
                                            <div class="flex justify-between items-start mb-3">
                                                <span
                                                    class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 line-through">
                                                    <i data-lucide="archive" class="w-4 h-4 mr-1"></i> Archived
                                                </span>
                                                <span
                                                    class="text-xs font-semibold text-gray-700 bg-gray-200 px-3 py-1 rounded-full"><?php echo htmlspecialchars($archive_event['category']); ?></span>
                                            </div>
                                            <h3 class="text-lg font-bold text-gray-600 mb-2">
                                                <?php echo htmlspecialchars($archive_event['title']); ?></h3>
                                            <p class="text-sm text-gray-500 mb-4">
                                                <?php echo substr(htmlspecialchars($archive_event['description']), 0, 100) . '...'; ?>
                                            </p>
                                        </div>

                                        <div class="mt-4 pt-4 border-t border-gray-100">
                                            <div class="flex items-center text-sm text-gray-500">
                                                <i data-lucide="calendar-off" class="w-4 h-4 mr-2 flex-shrink-0"></i>
                                                <span>Event Date:
                                                    <strong><?php echo date('M j, Y H:i', strtotime($archive_event['event_date'])); ?></strong></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="col-span-full text-center text-gray-500 p-4">No events in the recent archive.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div> <!-- End Event List -->

            </div> <!-- End Main Content Flex -->

        </main>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white p-4 mt-8">
            <div class="max-w-7xl mx-auto text-center text-sm">
                Local Community Event Bulletin Board (LCEBB) Prototype | Designed for IWP Course
            </div>
        </footer>
    </div>

    <!-- Initialize Lucide icons after all HTML content has been rendered -->
    <script>
        window.onload = function () {
            lucide.createIcons();

            // Scroll to form section if in edit mode or after submission for visibility
            if (window.location.hash === '#event-form-section' || "<?php echo !empty($message); ?>") {
                document.getElementById('event-form-section').scrollIntoView({ behavior: 'smooth' });
            }
        };
    </script>
</body>

</html>
<?php
// Close the database connection at the very end
$conn->close();
?>