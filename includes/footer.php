<?php
// /includes/footer.php
?>
        </main>
    </div> 
    
    <script>
        $(document).ready(function() {
            
            // --- 1. Mobile Sidebar Toggle Logic ---
            const sidebar = $('#sidebar');
            const overlay = $('#mobileOverlay');
            
            function openSidebar() {
                sidebar.removeClass('-translate-x-full');
                overlay.removeClass('hidden');
                // Tiny timeout to allow display block to render before triggering opacity transition
                setTimeout(() => overlay.removeClass('opacity-0'), 10);
            }
            
            function closeSidebar() {
                sidebar.addClass('-translate-x-full');
                overlay.addClass('opacity-0');
                setTimeout(() => overlay.addClass('hidden'), 300); // match duration-300
            }

            $('#mobileMenuBtn').on('click', openSidebar);
            $('#closeSidebar, #mobileOverlay').on('click', closeSidebar);

        });
    </script>
</body>
</html>