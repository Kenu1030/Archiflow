<?php
session_start();
require_once '../../backend/auth.php';
require_once '../../backend/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$user = $auth->getCurrentUser();

// Get design services from database
$db = getDB();
$services = [];
if ($db) {
    $query = "SELECT * FROM design_services ORDER BY service_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $services = $stmt->fetchAll();
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Design Services</h1>
                    <p class="text-gray-600 mt-2">Manage architectural design services and pricing</p>
                </div>
                <button onclick="openAddServiceModal()" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                    <i class="fas fa-plus mr-2"></i>
                    Add Service
                </button>
            </div>
        </div>

        <!-- Services Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($services)): ?>
                <div class="col-span-full">
                    <div class="bg-white rounded-lg shadow-lg p-12 text-center">
                        <i class="fas fa-palette text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Services Found</h3>
                        <p class="text-gray-600 mb-6">Start by adding your first design service</p>
                        <button onclick="openAddServiceModal()" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-300">
                            <i class="fas fa-plus mr-2"></i>
                            Add Service
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($services as $service): ?>
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden hover:shadow-xl transition duration-300">
                        <!-- Service Header -->
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-lg font-semibold"><?php echo htmlspecialchars($service['service_name']); ?></h3>
                                    <p class="text-blue-100 text-sm capitalize">
                                        <?php echo str_replace('_', ' ', $service['service_type']); ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-bold">₱<?php echo number_format($service['base_price'], 2); ?></div>
                                    <div class="text-blue-100 text-sm">Base Price</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Service Content -->
                        <div class="p-6">
                            <div class="mb-4">
                                <p class="text-gray-600 text-sm">
                                    <?php echo htmlspecialchars($service['description'] ?? 'No description available'); ?>
                                </p>
                            </div>
                            
                            <div class="space-y-3 mb-6">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500">Price per sqm:</span>
                                    <span class="font-semibold text-gray-900">
                                        ₱<?php echo number_format($service['price_per_sqm'], 2); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500">Service Type:</span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 capitalize">
                                        <?php echo str_replace('_', ' ', $service['service_type']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <div class="text-sm text-gray-500">
                                    Added <?php echo date('M j, Y', strtotime($service['created_at'])); ?>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="editService(<?php echo $service['service_id']; ?>)" class="text-indigo-600 hover:text-indigo-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteService(<?php echo $service['service_id']; ?>)" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Add Service Modal -->
<div id="addServiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <!-- Modal Header -->
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Add New Service</h3>
                <button onclick="closeAddServiceModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="addServiceForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Service Name *</label>
                    <input type="text" name="service_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter service name">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Service Type *</label>
                    <select name="service_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Service Type</option>
                        <option value="architectural_design">Architectural Design</option>
                        <option value="interior_design">Interior Design</option>
                        <option value="blueprint">Blueprint</option>
                        <option value="consultation">Consultation</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter service description"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Base Price *</label>
                        <input type="number" name="base_price" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price per sqm *</label>
                        <input type="number" name="price_per_sqm" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="0.00">
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeAddServiceModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition duration-300">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                        <i class="fas fa-plus mr-2"></i>
                        Add Service
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddServiceModal() {
    document.getElementById('addServiceModal').classList.remove('hidden');
}

function closeAddServiceModal() {
    document.getElementById('addServiceModal').classList.add('hidden');
    document.getElementById('addServiceForm').reset();
}

function editService(serviceId) {
    // Implement edit service functionality
    alert('Edit service: ' + serviceId);
}

function deleteService(serviceId) {
    if (confirm('Are you sure you want to delete this service?')) {
        // Implement delete service functionality
        alert('Delete service: ' + serviceId);
    }
}

// Handle form submission
document.getElementById('addServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'addService');
    
    fetch('../../backend/design-services.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Service added successfully!');
            closeAddServiceModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('An error occurred. Please try again.');
    });
});

// Close modal when clicking outside
document.getElementById('addServiceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddServiceModal();
    }
});
</script>

<?php include '../../backend/core/footer.php'; ?>
