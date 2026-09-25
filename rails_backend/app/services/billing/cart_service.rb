module Billing
  class CartService
    def get(user_id)
      row = Cart.find_by(user_id: user_id)
      return unless row

      JSON.parse(row.data).merge("_intent" => row.intent.to_i == 1)
    end

    def save(user_id, data, intent: false)
      serialized = data.to_h.except("_intent", :_intent)
      row = Cart.lock.find_or_initialize_by(user_id: user_id)
      row.update!(data: JSON.generate(serialized), intent: intent ? 1 : 0, updated_at: Time.now.to_i)
      row
    end

    def clear_intent(user_id)
      Cart.where(user_id: user_id).update_all(intent: 0, updated_at: Time.now.to_i)
    end

    def delete(user_id)
      Cart.where(user_id: user_id).delete_all
    end
  end
end
