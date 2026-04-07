# Base class and registration system for all tasks

class BaseTask:
    # Unique name for the task (must match job "task")
    name = ""
    required_params = []

    def validate(self, job):
        # Validate required parameters
        params = job.get("params", {})
        missing = [p for p in self.required_params if p not in params]
        if missing:
            raise ValueError(f"Missing params: {', '.join(missing)}")

    def run(self, job):
        # Must be implemented by each task
        raise NotImplementedError


# Global registry of tasks
TASKS = {}


def register_task(cls):
    # Decorator to auto-register task classes
    instance = cls()

    if not instance.name:
        raise ValueError(f"{cls.__name__} must define a task name")

    if instance.name in TASKS:
        raise ValueError(f"Duplicate task name: {instance.name}")

    TASKS[instance.name] = instance
    return cls
