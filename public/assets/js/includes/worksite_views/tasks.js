    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll('.load-more-comments').forEach(button => {
            button.addEventListener('click', function () {
                const taskId = this.dataset.taskId;
                const hiddenComments = document.querySelectorAll(`.task-comment-${taskId}.hidden`);
                let count = 0;

                hiddenComments.forEach(el => {
                    if (count < 5) {
                        el.classList.remove("hidden");
                        count++;
                    }
                });

                if (hiddenComments.length <= 5) {
                    this.style.display = 'none';
                }
            });
        });
    });
