FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PORT=8080

WORKDIR /app

COPY requirements.txt .
RUN python -m pip install --no-cache-dir --upgrade pip \
    && python -m pip install --no-cache-dir -r requirements.txt

COPY tala_solver ./tala_solver

RUN groupadd --gid 10001 tala \
    && useradd --uid 10001 --gid tala --no-log-init --create-home tala

USER tala

EXPOSE 8080
CMD ["sh", "-c", "exec gunicorn --bind 0.0.0.0:${PORT} --workers 1 --threads 2 --timeout 330 --access-logfile - --error-logfile - tala_solver.server:app"]
