namespace :zeleboba do
  desc "Run one payment outbox job"
  task run_one: :environment do
    abort "Set RAILS_OUTBOX_WORKER_ENABLED=1 to consume the shared production outbox." if Rails.env.production? && ENV["RAILS_OUTBOX_WORKER_ENABLED"] != "1"
    handled = Infrastructure::OutboxRunner.new.run_one
    puts handled ? "processed" : "idle"
  end

  desc "Run payment outbox jobs until the queue is empty"
  task drain: :environment do
    abort "Set RAILS_OUTBOX_WORKER_ENABLED=1 to consume the shared production outbox." if Rails.env.production? && ENV["RAILS_OUTBOX_WORKER_ENABLED"] != "1"
    runner = Infrastructure::OutboxRunner.new
    count = 0
    count += 1 while runner.run_one
    puts "processed=#{count}"
  end

  desc "Flush pending transactional email from the shared email queue"
  task flush_mail: :environment do
    abort "Set RAILS_SCHEDULER_ENABLED=1 to run Rails scheduled maintenance." if Rails.env.production? && ENV["RAILS_SCHEDULER_ENABLED"] != "1"
    ran = Infrastructure::ScheduledMaintenance.new.run
    puts ran ? "scheduled maintenance complete" : "scheduler disabled or PHP scheduler lease is active"
  end

  desc "Run the Rails outbox worker until SIGTERM/SIGINT"
  task work: :environment do
    abort "Set RAILS_OUTBOX_WORKER_ENABLED=1 before starting the Rails worker." if Rails.env.production? && ENV["RAILS_OUTBOX_WORKER_ENABLED"] != "1"
    running = true
    %w[INT TERM].each { |signal| Signal.trap(signal) { running = false } }
    runner = Infrastructure::OutboxRunner.new
    while running
      begin
        sleep 0.5 unless runner.run_one
      rescue StandardError => error
        warn "Rails outbox worker error: #{error.class.name}"
        sleep 1
      end
    end
  end
end
