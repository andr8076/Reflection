"""Command-line entrypoint for the Reflection farm agent."""

from reflection_agent.agent import FarmAgent


if __name__ == "__main__":
    agent = FarmAgent()
    agent.run_lifecycle()
