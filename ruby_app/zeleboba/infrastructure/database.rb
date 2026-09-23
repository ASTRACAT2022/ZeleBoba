# frozen_string_literal: true

require "json"
require "open3"
require "securerandom"
require "sequel"
require "tempfile"

module Zeleboba
  module Infrastructure
    class Database
      class ConstraintError < StandardError; end
      class QueryError < StandardError; end

      def initialize(dsn:, user: nil, password: nil)
        @adapter = if dsn.start_with?("sqlite:")
                     SQLiteCliAdapter.new(dsn)
                   else
                     SequelAdapter.new(dsn, user, password)
                   end
      end

      def postgres?
        @adapter.postgres?
      end

      def execute(sql, params = [])
        @adapter.execute(sql, params)
      end

      def all(sql, params = [])
        @adapter.all(sql, params)
      end

      def one(sql, params = [])
        all(sql, params).first
      end

      def transaction(&block)
        @adapter.transaction(&block)
      end

      def lock
        postgres? ? " FOR UPDATE" : ""
      end

      def migrate(directory)
        transaction do
          execute("SELECT pg_advisory_xact_lock(817421)") if postgres?
          execute("CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, applied_at BIGINT NOT NULL)")

          Dir[File.join(directory, "*.sql")].sort.each do |path|
            version = File.basename(path)
            next if one("SELECT version FROM migrations WHERE version = ?", [version])

            sql = File.read(path)
            sql = sqlite_sql(sql, version) unless postgres?
            @adapter.run_script(sql) unless sql.strip.empty?
            execute("INSERT INTO migrations VALUES (?, ?)", [version, Time.now.to_i])
          end
        end
      end

      def self.id
        SecureRandom.hex(16)
      end

      private

      def sqlite_sql(sql, version)
        portable = sql.gsub(/-- \[PG\]\R.*?-- \[\/PG\]\R/m, "")
        portable = portable.gsub("ADD COLUMN IF NOT EXISTS", "ADD COLUMN")
        portable = portable.gsub(/extract\(epoch from now\(\)\)::bigint/, "0")
        portable = portable.gsub(/^GRANT .*;\s*$/i, "")
        portable = portable.gsub(/^ALTER DEFAULT PRIVILEGES .*;\s*$/i, "")
        return portable unless version == "029_protection_framework.sql"

        portable.gsub(/^ALTER TABLE subscriptions\s+ADD COLUMN version .*;\s*$/i, "")
      end

      class SequelAdapter
        def initialize(dsn, user, password)
          @connection = Sequel.connect(connection_options(dsn, user, password))
          @connection.extension(:pg_json) if postgres?
        end

        def postgres?
          @connection.database_type == :postgres
        end

        def execute(sql, params = [])
          @connection[sql, *params].update
        rescue Sequel::UniqueConstraintViolation => e
          raise ConstraintError, e.message
        rescue Sequel::Error
          @connection[sql, *params].all
          0
        end

        def all(sql, params = [])
          @connection[sql, *params].all.map { |row| row.to_h.transform_keys(&:to_s) }
        end

        def transaction(&block)
          @connection.transaction(savepoint: true, &block)
        end

        def run_script(sql)
          @connection.run(sql)
        end

        private

        def connection_options(dsn, user, password)
          return dsn unless dsn.start_with?("pgsql:")

          params = dsn.delete_prefix("pgsql:").split(";").filter_map do |part|
            key, value = part.split("=", 2)
            [key, value] if key && value
          end.to_h
          query = []
          query << "user=#{user}" if user && !user.empty?
          query << "password=#{password}" if password && !password.empty?
          query << "host=#{params["host"]}" if params["host"]
          query << "port=#{params["port"]}" if params["port"]
          database = params["dbname"] || params["database"]
          "postgres://#{database}?#{query.join("&")}"
        end
      end

      class SQLiteCliAdapter
        def initialize(dsn)
          @path = if dsn == "sqlite::memory:"
                    file = Tempfile.new(["zeleboba-ruby-test", ".sqlite"])
                    file.close
                    @tempfile = file
                    file.path
                  else
                    dsn.delete_prefix("sqlite:")
                  end
          directory = File.dirname(@path)
          Dir.mkdir(directory) unless directory == "." || Dir.exist?(directory)
          run_script("PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 10000;")
          run_script("PRAGMA journal_mode = WAL;") unless dsn == "sqlite::memory:"
        end

        def postgres?
          false
        end

        def execute(sql, params = [])
          run_script(interpolate(sql, params))
          0
        end

        def all(sql, params = [])
          stdout = sqlite_json(interpolate(sql, params))
          return [] if stdout.strip.empty?

          JSON.parse(stdout)
        end

        def transaction
          yield
        end

        def run_script(sql)
          with_busy_retry do
            _stdout, stderr, status = Open3.capture3("sqlite3", @path, stdin_data: sql)
            handle_error(stderr) unless status.success?
          end
        end

        private

        def sqlite_json(sql)
          with_busy_retry do
            stdout, stderr, status = Open3.capture3("sqlite3", "-json", @path, sql)
            handle_error(stderr) unless status.success?
            stdout
          end
        end

        def interpolate(sql, params)
          values = params.dup
          sql.gsub("?") do
            raise QueryError, "missing SQL parameter" if values.empty?

            quote(values.shift)
          end
        end

        def quote(value)
          case value
          when nil
            "NULL"
          when Integer
            value.to_s
          when Float
            value.to_s
          else
            "'#{value.to_s.gsub("'", "''")}'"
          end
        end

        def handle_error(stderr)
          message = stderr.to_s.strip
          if message.include?("UNIQUE constraint failed") || message.include?("constraint failed")
            raise ConstraintError, message
          end

          raise QueryError, message
        end

        def with_busy_retry
          attempts = 0
          begin
            yield
          rescue QueryError => e
            attempts += 1
            raise unless e.message.include?("database is locked") && attempts < 20

            sleep(0.1)
            retry
          end
        end
      end
    end
  end
end
