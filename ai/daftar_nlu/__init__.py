"""daftar_nlu — a small, dependency-free Arabic NLU engine for the «مساعد AI» of حلول.

It turns a merchant's free-text command (Levantine / Gulf / MSA Arabic) into an
intent plus entities. It only *understands* text; every database action is done
by Laravel, which keeps tenant isolation in one place.
"""

__version__ = "1.0.0"
