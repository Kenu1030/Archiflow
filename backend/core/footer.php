    <?php if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $__isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    $__userType = $_SESSION['user_type'] ?? null;
    $__hasSidebar = $__isLoggedIn && !empty($__userType);
    // Allow pages to suppress footer by setting $HIDE_FOOTER = true before including this file
    $__suppressFooter = !empty($HIDE_FOOTER);
    ?>

    <!-- Close main content divs opened in header BEFORE rendering the footer so it spans full width -->
    <?php if ($__hasSidebar): ?>
    </div> <!-- Close mainContent div -->
    </div> <!-- Close flex container -->
    <?php else: ?>
    </div> <!-- Close mainContent div -->
    <?php endif; ?>

    <?php // Show footer for guests and non-admin roles only, unless page explicitly hides it
    if (!$__suppressFooter && !($__isLoggedIn && $__userType === 'admin')): ?>
    <!-- Footer (hidden for admin) -->
    <footer class="bg-gray-800 text-white py-12 mt-auto">
        <div class="max-w-full px-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <i class="fas fa-building text-blue-400 text-2xl"></i>
                        <span class="text-xl font-bold">ArchiFlow</span>
                    </div>
                    <p class="text-gray-400">
                        Streamlining architectural project management through innovative digital solutions.
                    </p>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                    <ul class="space-y-2">
                        <li><a href="index.php" class="text-gray-400 hover:text-white">Home</a></li>
                        <li><a href="index.php#features" class="text-gray-400 hover:text-white">Features</a></li>
                        <li><a href="index.php#about" class="text-gray-400 hover:text-white">About</a></li>
                        <li><a href="index.php#contact" class="text-gray-400 hover:text-white">Contact</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold mb-4">Contact Us</h3>
                    <address class="not-italic text-gray-400">
                        <p class="mb-2"><i class="fas fa-map-marker-alt mr-2"></i> 2F The Rosedale Place, Gov. M Cuenco Ave, Banilad, Cebu City, Philippines 6000</p>
                        <p class="mb-2"><i class="fas fa-phone mr-2"></i> +123 456 7890</p>
                        <p><i class="fas fa-envelope mr-2"></i> info@archiflow.com</p>
                    </address>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold mb-4">Connect With Us</h3>
                    <div class="flex space-x-4">
                        <a href="#" class="text-gray-400 hover:text-white text-xl"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-gray-400 hover:text-white text-xl"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-gray-400 hover:text-white text-xl"><i class="fab fa-linkedin"></i></a>
                        <a href="#" class="text-gray-400 hover:text-white text-xl"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            
            <div class="border-t border-gray-700 mt-8 pt-8 text-center text-gray-400">
                <p>&copy; <?php echo date('Y'); ?> ArchiFlow. All rights reserved.</p>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <!-- JavaScript for Interactive Features -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuBtn && mobileMenu) {
                mobileMenuBtn.addEventListener('click', function() {
                    mobileMenu.classList.toggle('-translate-x-full');
                    mobileMenu.classList.toggle('translate-x-0');
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-bars');
                    icon.classList.toggle('fa-times');
                });
                
                // Close mobile menu when clicking on links
                const mobileLinks = mobileMenu.querySelectorAll('a');
                mobileLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        mobileMenu.classList.add('-translate-x-full');
                        mobileMenu.classList.remove('translate-x-0');
                        const icon = mobileMenuBtn.querySelector('i');
                        icon.classList.add('fa-bars');
                        icon.classList.remove('fa-times');
                    });
                });
            }
            
            // Sidebar toggle for mobile
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebar.classList.toggle('translate-x-0');
                });
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(e) {
                    if (window.innerWidth < 1024) { // lg breakpoint
                        if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                            sidebar.classList.add('-translate-x-full');
                            sidebar.classList.remove('translate-x-0');
                        }
                    }
                });
            }
            
            // Logout function
            window.logout = function() {
                if (confirm('Are you sure you want to logout?')) {
                    fetch('backend/auth.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=logout'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = 'login.php';
                        } else {
                            alert('Logout failed. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Logout error:', error);
                        // Force redirect even if request fails
                        window.location.href = 'login.php';
                    });
                }
            };
            
            // Scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.remove('opacity-0', 'translate-y-8', '-translate-x-12', 'translate-x-12');
                        entry.target.classList.add('opacity-100', 'translate-y-0', 'translate-x-0');
                    }
                });
            }, observerOptions);
            
            // Observe elements for animation
            const animatedElements = document.querySelectorAll('.opacity-0');
            animatedElements.forEach(el => {
                observer.observe(el);
            });
            
            // Counter animation
            function animateCounters() {
                const counters = document.querySelectorAll('[data-counter]');
                counters.forEach(counter => {
                    const target = parseInt(counter.getAttribute('data-counter'));
                    const duration = 2000; // 2 seconds
                    const increment = target / (duration / 16); // 60fps
                    let current = 0;
                    
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        counter.textContent = Math.floor(current) + (counter.textContent.includes('+') ? '+' : '');
                    }, 16);
                });
            }
            
            // Trigger counter animation when stats section is visible
            const statsSection = document.querySelector('.grid.grid-cols-2.gap-6');
            if (statsSection) {
                const statsObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            animateCounters();
                            statsObserver.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.5 });
                
                statsObserver.observe(statsSection);
            }
            
            // Smooth scroll for anchor links
            const anchorLinks = document.querySelectorAll('a[href^="#"]');
            anchorLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetElement = document.getElementById(targetId);
                    
                    if (targetElement) {
                        const offsetTop = targetElement.offsetTop - 80; // Account for fixed header
                        window.scrollTo({
                            top: offsetTop,
                            behavior: 'smooth'
                        });
                    }
                });
            });
            
            // Add loading states to buttons
            const buttons = document.querySelectorAll('button[type="submit"]');
            buttons.forEach(button => {
                button.addEventListener('click', function() {
                    if (this.form && this.form.checkValidity()) {
                        this.classList.add('loading');
                        const originalText = this.textContent;
                        this.textContent = 'Loading...';
                        
                        // Remove loading state after 3 seconds (fallback)
                        setTimeout(() => {
                            this.classList.remove('loading');
                            this.textContent = originalText;
                        }, 3000);
                    }
                });
            });
            
            // Form validation feedback
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const inputs = form.querySelectorAll('input, textarea, select');
                inputs.forEach(input => {
                    input.addEventListener('blur', function() {
                        validateField(this);
                    });
                    
                    input.addEventListener('input', function() {
                        if (this.classList.contains('error')) {
                            validateField(this);
                        }
                    });
                });
            });
            
            function validateField(field) {
                const value = field.value.trim();
                const type = field.type;
                const required = field.hasAttribute('required');
                
                // Remove previous error styling
                field.classList.remove('error', 'success');
                
                if (required && !value) {
                    field.classList.add('error');
                    return false;
                }
                
                if (value) {
                    if (type === 'email' && !isValidEmail(value)) {
                        field.classList.add('error');
                        return false;
                    }
                    
                    if (type === 'password' && value.length < 6) {
                        field.classList.add('error');
                        return false;
                    }
                    
                    field.classList.add('success');
                }
                
                return true;
            }
            
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
            }
            
            // // Parallax effect for hero section
            // const heroSection = document.querySelector('.bg-gradient-to-br.from-blue-900');
            // if (heroSection) {
            //     window.addEventListener('scroll', function() {
            //         const scrolled = window.pageYOffset;
            //         const parallax = scrolled * 0.5;
            //         heroSection.style.transform = `translateY(${parallax}px)`;
            //     });
            // }
        });
    </script>
    
    <!-- main content wrappers were closed above -->
</body>
</html>