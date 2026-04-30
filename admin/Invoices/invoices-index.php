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

// Get invoices from database
$db = getDB();
$invoices = [];
if ($db) {
    $query = "SELECT i.*, p.project_name, c.contact_person as client_name 
              FROM invoices i 
              LEFT JOIN projects p ON i.project_id = p.project_id 
              LEFT JOIN clients c ON i.client_id = c.client_id 
              ORDER BY i.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $invoices = $stmt->fetchAll();
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Invoice Management</h1>
                    <p class="text-gray-600 mt-2">Manage project invoices and billing</p>
                </div>
                <button onclick="openAddInvoiceModal()" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                    <i class="fas fa-plus mr-2"></i>
                    Create Invoice
                </button>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">All Invoices</h2>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-file-invoice text-4xl mb-4"></i>
                                    <p class="text-lg">No invoices found</p>
                                    <p class="text-sm">Start by creating your first invoice</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                ID: <?php echo $invoice['invoice_id']; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($invoice['project_name'] ?? 'No project'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($invoice['client_name'] ?? 'No client'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            ₱<?php echo number_format($invoice['total_amount'], 2); ?>
                                        </div>
                                        <?php if ($invoice['paid_amount'] > 0): ?>
                                            <div class="text-sm text-green-600">
                                                Paid: ₱<?php echo number_format($invoice['paid_amount'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusColor = '';
                                        $statusIcon = '';
                                        
                                        switch ($invoice['status']) {
                                            case 'draft':
                                                $statusColor = 'bg-gray-100 text-gray-800';
                                                $statusIcon = 'fas fa-edit';
                                                break;
                                            case 'sent':
                                                $statusColor = 'bg-blue-100 text-blue-800';
                                                $statusIcon = 'fas fa-paper-plane';
                                                break;
                                            case 'paid':
                                                $statusColor = 'bg-green-100 text-green-800';
                                                $statusIcon = 'fas fa-check-circle';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
                                            <i class="<?php echo $statusIcon; ?> mr-1"></i>
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($invoice['invoice_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="viewInvoice(<?php echo $invoice['invoice_id']; ?>)" class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="editInvoice(<?php echo $invoice['invoice_id']; ?>)" class="text-indigo-600 hover:text-indigo-900">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="downloadInvoice(<?php echo $invoice['invoice_id']; ?>)" class="text-green-600 hover:text-green-900">
                                                <i class="fas fa-download"></i>
                                            </button>
                                            <button onclick="deleteInvoice(<?php echo $invoice['invoice_id']; ?>)" class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Invoice Modal -->
<div id="addInvoiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <!-- Modal Header -->
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Create New Invoice</h3>
                <button onclick="closeAddInvoiceModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="addInvoiceForm" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Number *</label>
                        <input type="text" name="invoice_number" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter invoice number">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Date *</label>
                        <input type="date" name="invoice_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Project *</label>
                        <select name="project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Project</option>
                            <!-- Projects will be loaded here -->
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Client *</label>
                        <select name="client_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Client</option>
                            <!-- Clients will be loaded here -->
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount *</label>
                        <input type="number" name="total_amount" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
                        <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>

                <!-- Modal Footer -->
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeAddInvoiceModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition duration-300">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                        <i class="fas fa-plus mr-2"></i>
                        Create Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddInvoiceModal() {
    document.getElementById('addInvoiceModal').classList.remove('hidden');
}

function closeAddInvoiceModal() {
    document.getElementById('addInvoiceModal').classList.add('hidden');
    document.getElementById('addInvoiceForm').reset();
}

function viewInvoice(invoiceId) {
    // Implement view invoice functionality
    alert('View invoice: ' + invoiceId);
}

function editInvoice(invoiceId) {
    // Implement edit invoice functionality
    alert('Edit invoice: ' + invoiceId);
}

function downloadInvoice(invoiceId) {
    // Implement download invoice functionality
    alert('Download invoice: ' + invoiceId);
}

function deleteInvoice(invoiceId) {
    if (confirm('Are you sure you want to delete this invoice?')) {
        // Implement delete invoice functionality
        alert('Delete invoice: ' + invoiceId);
    }
}

// Handle form submission
document.getElementById('addInvoiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'addInvoice');
    
    fetch('../../backend/invoices.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Invoice created successfully!');
            closeAddInvoiceModal();
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
document.getElementById('addInvoiceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddInvoiceModal();
    }
});
</script>

<?php include '../../backend/core/footer.php'; ?>
