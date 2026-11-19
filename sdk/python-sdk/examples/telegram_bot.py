"""Minimal python-telegram-bot v20+ example. Keep credentials server-side."""
import os
import asyncio
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, ContextTypes
from tggateways import TGGateway

gateway = TGGateway(os.environ["TG_BASE_URL"], os.environ["TG_API_KEY"])

async def buy(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    order = await asyncio.to_thread(gateway.create_order, 49.00, customer_name=update.effective_user.first_name)
    keyboard = [[InlineKeyboardButton("Pay ₹49", url=order["checkout_url"])]]
    await update.message.reply_text("Complete your payment using this secure checkout:",
                                    reply_markup=InlineKeyboardMarkup(keyboard))

app = Application.builder().token(os.environ["TELEGRAM_BOT_TOKEN"]).build()
app.add_handler(CommandHandler("buy", buy))
app.run_polling()
