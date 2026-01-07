<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary-blue: #2c5aa0;
            --primary-blue-dark: #1c3a6e;
            --primary-blue-light: #4a7fd4;
            --accent-blue: #3498db;
            --hover-blue: #3b6cb0;
            --sidebar-bg: #ffffff;
            --sidebar-text: #2c3e50;
            --sidebar-hover: #f0f7ff;
            --main-bg: #f8fafc;
            --white: #ffffff;
            --border-color: #e2e8f0;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            display: flex;
            min-height: 100vh;
            background: var(--main-bg);
        }

        /* Simple Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: var(--transition);
            box-shadow: var(--shadow);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            overflow: hidden;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar-header {
            padding: 24px 20px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            transition: var(--transition);
        }

        .sidebar-header:hover {
            background: linear-gradient(135deg, var(--primary-blue-light), var(--primary-blue));
        }

        .logo {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
        }

        .logo:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.3);
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            white-space: nowrap;
            color: white;
            letter-spacing: 0.5px;
            transition: var(--transition);
        }

        .sidebar.collapsed .logo-text {
            opacity: 0;
            width: 0;
        }

        /* Keep the toggle button styling but make it hidden */
        .toggle-btn {
            display: none;
        }

        /* Navigation */
        .nav-menu {
            padding: 20px 0;
            overflow-y: auto;
            height: calc(100vh - 100px);
        }

        .nav-section {
            padding: 0 0 10px 0;
        }

        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            padding: 15px 24px 8px 24px;
            font-weight: 600;
            transition: var(--transition);
        }

        .sidebar.collapsed .nav-section-title {
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
        }

        .nav-item {
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            margin: 2px 8px;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: var(--accent-blue);
            transform: scaleY(0);
            transition: transform 0.3s ease;
            border-radius: 0 4px 4px 0;
        }

        .nav-item:hover {
            background: var(--sidebar-hover);
            transform: translateX(5px);
            color: var(--primary-blue);
            border-left-color: var(--accent-blue);
        }

        .nav-item:hover::before {
            transform: scaleY(1);
        }

        .nav-item:hover .nav-icon {
            transform: scale(1.1);
            color: var(--accent-blue);
        }

        .nav-item.active {
            background: linear-gradient(90deg, rgba(52, 152, 219, 0.1), rgba(52, 152, 219, 0.05));
            color: var(--primary-blue);
            border-left-color: var(--accent-blue);
            font-weight: 600;
            box-shadow: inset 4px 0 0 var(--accent-blue);
        }

        .nav-item.active .nav-icon {
            color: var(--accent-blue);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .nav-icon {
            width: 24px;
            text-align: center;
            font-size: 20px;
            color: #64748b;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .nav-text {
            font-size: 15px;
            font-weight: 500;
            white-space: nowrap;
            transition: var(--transition);
        }

        .sidebar.collapsed .nav-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .badge {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 12px;
            margin-left: auto;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
            animation: badgePulse 3s infinite;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
        }

        @keyframes badgePulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .sidebar.collapsed .badge {
            display: none;
        }

        /* User Profile Section */
        .user-profile {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px;
            border-top: 1px solid var(--border-color);
            background: var(--sidebar-bg);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: var(--transition);
        }

        .sidebar.collapsed .user-profile {
            justify-content: center;
            padding: 20px 0;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 20px;
            flex-shrink: 0;
            transition: var(--transition);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.2);
        }

        .user-avatar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.3);
        }

        .user-info {
            flex-grow: 1;
            overflow: hidden;
            transition: var(--transition);
        }

        .sidebar.collapsed .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .user-name {
            color: var(--sidebar-text);
            font-weight: 600;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            color: #64748b;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            padding: 0;
            min-height: 100vh;
            background: var(--main-bg);
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: 80px;
        }

        /* Top Bar */
        .top-bar {
            background: var(--white);
            padding: 18px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 999;
            border-bottom: 1px solid var(--border-color);
        }

        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--primary-blue);
            cursor: pointer;
            transition: var(--transition);
            padding: 8px;
            border-radius: 8px;
        }

        .mobile-menu-btn:hover {
            background: var(--sidebar-hover);
            transform: rotate(90deg);
        }

        .page-title h1 {
            font-size: 26px;
            color: var(--primary-blue);
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-title p {
            color: #64748b;
            font-size: 14px;
            margin-top: 4px;
        }

        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .notification-icon {
            position: relative;
            cursor: pointer;
            color: var(--sidebar-text);
            padding: 10px;
            border-radius: 10px;
            transition: var(--transition);
        }

        .notification-icon:hover {
            background: var(--sidebar-hover);
            color: var(--accent-blue);
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            font-size: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            animation: badgePulse 3s infinite;
        }

        .user-menu-top {
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            padding: 10px 16px;
            border-radius: 12px;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .user-menu-top:hover {
            background: var(--sidebar-hover);
            border-color: var(--accent-blue);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.1);
        }

        /* Content Box */
        .content-wrapper {
            padding: 30px;
        }

        .content-box {
            background: var(--white);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .content-box:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
                box-shadow: 0 0 40px rgba(0, 0, 0, 0.2);
            }

            .main-content {
                margin-left: 0 !important;
                width: 100%;
            }

            .mobile-menu-btn {
                display: block;
            }

            .top-bar {
                padding: 15px 20px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px;
            }
            
            .content-box {
                padding: 20px;
            }
            
            .top-bar-right {
                gap: 15px;
            }
            
            .user-menu-top {
                padding: 8px 12px;
            }
        }

        @media (max-width: 640px) {
            .page-title h1 {
                font-size: 22px;
            }
            
            .notification-icon {
                display: none;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Simple Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo" id="logoToggle">
                <i class="fas fa-clinic-medical"></i>
            </div>
            <div class="logo-text">Clinic Records</div>
            <!-- Hidden button but keeping it for structure -->
            <button class="toggle-btn" id="toggleBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <div class="nav-menu">
            <div class="nav-section">
                <div class="nav-section-title">Main Menu</div>
             <a href="/clinic/admin/admin.php" class="nav-item ">

                    <div class="nav-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="nav-text">Dashboard</div>
                </a>

             <a href="/clinic/admin/pages/patientrecords.php" class="nav-item ">

                    <div class="nav-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="nav-text">Patients</div>
              
                </a>

                <a href="/clinic/admin/pages/recordslogs.php" class="nav-item">
                    <div class="nav-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="nav-text">Records</div>
                  
                </a>

                
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Management</div>
         

                <a href="/clinic/admin/pages/reportsComplaint.php" class="nav-item">
                    <div class="nav-icon">
                       <i class="fa-solid fa-chart-column"></i>
                    </div>
                    <div class="nav-text">Reports Complaint</div>
                </a>


                <a href="/clinic/admin/pages/reportslogs.php" class="nav-item">
                    <div class="nav-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="nav-text">Reports Logs</div>
                </a>

                <a href="/clinic/admin/pages/statistics.php" class="nav-item">
                    <div class="nav-icon">
                 <i class="fa-solid fa-chart-area"></i>
                    </div>
                    <div class="nav-text">Statistics</div>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Settings</div>
     

                <a href="#" class="nav-item">
                    <div class="nav-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="nav-text">Settings</div>
                </a>

                <a href="#" class="nav-item">
                    <div class="nav-icon">
                        <i class="fas fa-sign-out-alt"></i>
                    </div>
                    <div class="nav-text">Logout</div>
                </a>
            </div>
        </div>

    </div>

    

    <script>
        // Simple sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const logoToggle = document.getElementById('logoToggle');
        const toggleBtn = document.getElementById('toggleBtn');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        // Desktop toggle - Logo click
        logoToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            const icon = toggleBtn.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
                icon.style.transform = 'rotate(0deg)';
            } else {
                icon.className = 'fas fa-chevron-left';
                icon.style.transform = 'rotate(0deg)';
            }
        });

        // Mobile menu toggle with animation
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            const icon = mobileMenuBtn.querySelector('i');
            if (sidebar.classList.contains('active')) {
                icon.className = 'fas fa-times';
            } else {
                icon.className = 'fas fa-bars';
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                    const icon = mobileMenuBtn.querySelector('i');
                    icon.className = 'fas fa-bars';
                }
            }
        });

        // Set active nav item with ripple effect
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all items
                navItems.forEach(nav => nav.classList.remove('active'));
                
                // Add active class to clicked item
                this.classList.add('active');
                
                // Create ripple effect
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(52, 152, 219, 0.2);
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    width: ${size}px;
                    height: ${size}px;
                    top: ${y}px;
                    left: ${x}px;
                    pointer-events: none;
                `;
                
                this.appendChild(ripple);
                
                // Remove ripple after animation
                setTimeout(() => {
                    ripple.remove();
                }, 600);
                
                // Close sidebar on mobile after selection
                if (window.innerWidth <= 1024) {
                    sidebar.classList.remove('active');
                    const icon = mobileMenuBtn.querySelector('i');
                    icon.className = 'fas fa-bars';
                }
            });
        });

        // Add ripple animation to CSS
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Check screen size for mobile
        function checkScreenSize() {
            if (window.innerWidth <= 1024) {
                mobileMenuBtn.style.display = 'block';
                sidebar.classList.remove('collapsed');
            } else {
                mobileMenuBtn.style.display = 'none';
                sidebar.classList.remove('active');
                const icon = mobileMenuBtn.querySelector('i');
                icon.className = 'fas fa-bars';
            }
        }

        // Run on page load and resize
        document.addEventListener('DOMContentLoaded', checkScreenSize);
        window.addEventListener('resize', checkScreenSize);
    </script>
</body>
</html>