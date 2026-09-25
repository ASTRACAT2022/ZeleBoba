require "rails"
require "active_model/railtie"
require "active_record/railtie"
require "action_controller/railtie"
require "active_support/railtie"

Bundler.require(*Rails.groups)

module ZelebobaRails
  class Application < Rails::Application
    config.load_defaults 8.1
    config.api_only = true
    config.time_zone = "UTC"
    config.force_ssl = true if Rails.env.production?
    config.filter_parameters += %i[password password_confirmation code token secret authorization]
    config.log_level = ENV.fetch("RAILS_LOG_LEVEL", Rails.env.production? ? "info" : "debug")
    config.eager_load_paths << Rails.root.join("app/services")
    config.autoload_lib(ignore: %w[assets tasks])
  end
end
