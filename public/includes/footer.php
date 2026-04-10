    <script>
      // Theme Toggle Logic
      const themeToggle = document.getElementById("themeToggle");
      const html = document.documentElement;

      const savedTheme = localStorage.getItem("theme");
      if (savedTheme === "dark") {
        html.classList.add("dark");
      }

      themeToggle?.addEventListener("click", () => {
        html.classList.toggle("dark");
        const isDark = html.classList.contains("dark");
        localStorage.setItem("theme", isDark ? "dark" : "light");
      });
    </script>
    <?php if (isset($extraJs)): foreach ($extraJs as $js): ?>
      <script src="/js/<?php echo $js; ?>"></script>
    <?php endforeach; endif; ?>
  </body>
</html>
